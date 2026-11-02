<?php

declare(strict_types=1);

namespace App\Sharing;

use App\Entity\SyncView;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Sync\SyncLimits;
use App\Sync\SyncLock;
use App\Sync\VersionPublisher;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Teilen einzelner Listen (CLAUDE.md 12).
 *
 * Jede Aenderung an Mitgliedschaften laeuft unter der exklusiven SyncLock-Sperre
 * und vergibt Versionen in allen betroffenen Konten: Wer beitritt, bekommt die
 * Liste samt Artikeln in seinen Pull; wer den Zugriff verliert, bekommt sie als
 * `revoked`; alle anderen sehen die neue Mitgliederzahl.
 */
final class ShareService
{
    /** Crockford-Base32 ohne I, L, O, U: gut abtippbar, nicht verwechselbar. */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    public const CODE_LENGTH = 24;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ListAccess $access,
        private readonly SyncLock $lock,
    ) {
    }

    /** Vorhandenen Code liefern oder einen neuen anlegen. Nur der Besitzer. */
    public function share(User $user, string $listId): string
    {
        return $this->transactional(function (Connection $connection) use ($user, $listId): string {
            $this->requireOwner($user, $listId);
            /** @var string|false|null $code */
            $code = $connection->fetchOne('SELECT share_code FROM shopping_list WHERE id = :id', ['id' => $listId]);

            return \is_string($code) ? $code : $this->storeNewCode($connection, $listId);
        });
    }

    /** Neuer Code; der alte wird ungueltig. Bestehende Mitglieder bleiben. */
    public function renew(User $user, string $listId): string
    {
        return $this->transactional(function (Connection $connection) use ($user, $listId): string {
            $this->requireOwner($user, $listId);

            return $this->storeNewCode($connection, $listId);
        });
    }

    /** Teilen beenden: Code ungueltig, alle Mitglieder verlieren den Zugriff. */
    public function stop(User $user, string $listId): void
    {
        $this->transactional(function (Connection $connection) use ($user, $listId): void {
            $this->lock->exclusive($connection);
            $audience = $this->requireOwner($user, $listId);

            $publisher = VersionPublisher::lock($connection, $audience->all());
            $items = $this->access->itemIdsOfList($listId);
            foreach ($audience->members as $member) {
                $publisher->revoke($member, $listId, $items);
            }
            $this->touchList($connection, $publisher, $listId, new ListAudience($audience->owner));

            $connection->executeStatement('DELETE FROM list_member WHERE list_id = :id', ['id' => $listId]);
            $connection->executeStatement('UPDATE shopping_list SET share_code = NULL WHERE id = :id', ['id' => $listId]);
            $publisher->flush($connection);
        });
    }

    /**
     * Einer Liste ueber ihren Code beitreten. Idempotent: Besitzer und
     * bestehende Mitglieder bekommen einfach die Listen-ID zurueck.
     */
    public function join(User $user, string $code, \DateTimeImmutable $now): string
    {
        $code = self::normalize($code);

        return $this->transactional(function (Connection $connection) use ($user, $code, $now): string {
            $this->lock->exclusive($connection);
            $userId = $user->getId()->toRfc4122();

            /** @var string|false $listId */
            $listId = '' === $code ? false : $connection->fetchOne('SELECT id FROM shopping_list WHERE share_code = :code', ['code' => $code]);
            if (false === $listId) {
                throw ApiProblemException::notFound('Unbekannter oder nicht mehr gueltiger Teilen-Code.');
            }

            $audience = $this->access->forLists([$listId])[$listId];
            if ($audience->includes($userId)) {
                return $listId;
            }
            if (\count($audience->members) >= SyncLimits::MAX_MEMBERS_PER_LIST) {
                throw ApiProblemException::conflict('Diese Liste ist bereits mit der maximalen Zahl an Personen geteilt.');
            }
            if ($this->access->countMemberships($userId) >= SyncLimits::MAX_MEMBERSHIPS_PER_USER) {
                throw ApiProblemException::conflict('Maximale Zahl geteilter Listen erreicht.');
            }

            $connection->executeStatement(
                'INSERT INTO list_member (list_id, user_id, joined_at) VALUES (:list, :user, :now)',
                ['list' => $listId, 'user' => $userId, 'now' => $now->format('Y-m-d H:i:s.uP')],
            );

            $publisher = VersionPublisher::lock($connection, [...$audience->all(), $userId]);
            $publisher->unrevoke($userId, $listId);

            // Die Liste fuer alle (neue Mitgliederzahl), danach jeden Artikel
            // fuer das neue Mitglied – in dieser Reihenfolge, damit kein Artikel
            // vor seiner Liste beim Client ankommt.
            $joined = new ListAudience($audience->owner, [...$audience->members, $userId]);
            $this->touchList($connection, $publisher, $listId, $joined);
            foreach ($this->access->itemIdsOfList($listId) as $itemId) {
                $publisher->publish(SyncView::KIND_ITEM, $itemId, [$userId]);
            }

            $publisher->flush($connection);

            return $listId;
        });
    }

    /** Als Mitglied eine geteilte Liste verlassen. */
    public function leave(User $user, string $listId): void
    {
        $this->transactional(function (Connection $connection) use ($user, $listId): void {
            $this->lock->exclusive($connection);
            $userId = $user->getId()->toRfc4122();

            $audience = $this->access->forLists([$listId])[$listId] ?? null;
            if (null === $audience || !$audience->includes($userId)) {
                throw ApiProblemException::notFound('Liste nicht gefunden.');
            }
            if ($audience->owner === $userId) {
                throw ApiProblemException::conflict('Der Besitzer kann seine Liste nicht verlassen, nur das Teilen beenden.');
            }

            $this->removeMember($connection, $listId, $audience, $userId);
        });
    }

    /**
     * Vor `DELETE /v1/me`: Mitglieder geteilter Listen dieses Kontos verlieren
     * den Zugriff, und aus fremden Listen tritt es aus. Muss in derselben
     * Transaktion laufen wie das Loeschen selbst.
     */
    public function detachAccount(Connection $connection, User $user): void
    {
        $this->lock->exclusive($connection);
        $userId = $user->getId()->toRfc4122();

        foreach ($this->access->forLists($this->access->sharedListIdsOf($userId)) as $listId => $audience) {
            if ($audience->owner === $userId) {
                $publisher = VersionPublisher::lock($connection, $audience->members);
                $items = $this->access->itemIdsOfList($listId);
                foreach ($audience->members as $member) {
                    $publisher->revoke($member, $listId, $items);
                }
                $publisher->flush($connection);
            } else {
                $this->removeMember($connection, $listId, $audience, $userId);
            }
        }
    }

    public static function normalize(string $code): string
    {
        // Abgetippte Codes: Gruppierung, Kleinschreibung und die bei
        // Crockford-Base32 ueblichen Verwechslungen tolerieren.
        $code = strtoupper(preg_replace('/[\s-]+/', '', $code) ?? '');

        return strtr($code, ['O' => '0', 'I' => '1', 'L' => '1']);
    }

    private function removeMember(Connection $connection, string $listId, ListAudience $audience, string $memberId): void
    {
        $publisher = VersionPublisher::lock($connection, $audience->all());
        $publisher->revoke($memberId, $listId, $this->access->itemIdsOfList($listId));
        $this->touchList($connection, $publisher, $listId, $audience->without($memberId));

        $connection->executeStatement(
            'DELETE FROM list_member WHERE list_id = :list AND user_id = :user',
            ['list' => $listId, 'user' => $memberId],
        );
        $publisher->flush($connection);
    }

    /**
     * Liefert die Liste allen Teilnehmern neu aus, ohne ihren Inhalt zu
     * aendern – fuer die geaenderte Mitgliederzahl.
     */
    private function touchList(Connection $connection, VersionPublisher $publisher, string $listId, ListAudience $audience): void
    {
        $versions = $publisher->publish(SyncView::KIND_LIST, $listId, $audience->all());
        $connection->executeStatement(
            'UPDATE shopping_list SET server_version = :version WHERE id = :id',
            ['version' => $versions[$audience->owner], 'id' => $listId],
        );
    }

    private function requireOwner(User $user, string $listId): ListAudience
    {
        $audience = $this->access->forLists([$listId])[$listId] ?? null;
        $userId = $user->getId()->toRfc4122();

        if (null === $audience || !$audience->includes($userId)) {
            throw ApiProblemException::notFound('Liste nicht gefunden.');
        }
        if ($audience->owner !== $userId) {
            throw ApiProblemException::forbidden('Nur der Besitzer kann diese Liste teilen.');
        }

        return $audience;
    }

    private function storeNewCode(Connection $connection, string $listId): string
    {
        $code = '';
        $bytes = random_bytes(self::CODE_LENGTH);
        for ($i = 0; $i < self::CODE_LENGTH; ++$i) {
            // 256 ist ein Vielfaches von 32: gleichverteilt, 120 Bit insgesamt.
            $code .= self::ALPHABET[\ord($bytes[$i]) % 32];
        }

        $connection->executeStatement('UPDATE shopping_list SET share_code = :code WHERE id = :id', ['code' => $code, 'id' => $listId]);

        return $code;
    }

    /**
     * @template T
     *
     * @param callable(Connection): T $work
     *
     * @return T
     */
    private function transactional(callable $work): mixed
    {
        $connection = $this->em->getConnection();

        return $connection->transactional(static fn (): mixed => $work($connection));
    }
}
