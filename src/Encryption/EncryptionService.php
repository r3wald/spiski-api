<?php

declare(strict_types=1);

namespace App\Encryption;

use App\Entity\EncryptionState;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Live\LiveUpdates;
use App\Sharing\ShareService;
use App\Sync\SyncLock;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Umstellung eines Kontos auf Ende-zu-Ende-Verschluesselung (CLAUDE.md 14):
 * plaintext -> migrating (`begin`) -> encrypted (`commit`).
 *
 * Der Zustand aendert sich nur hier, unter der exklusiven SyncLock-Sperre und
 * der Sperre der Kontozeile. Der Sync liest ihn unter derselben Kontosperre
 * (SyncService::guardEncryption) und sieht ihn deshalb immer aktuell.
 */
final class EncryptionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SyncLock $lock,
        private readonly ShareService $shares,
        private readonly LiveUpdates $live,
    ) {
    }

    /**
     * @return array{state: string, keyCheck: string|null}
     */
    public function status(User $user): array
    {
        /** @var array{encryption_state: string, key_check: string|null}|false $row */
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT encryption_state, key_check FROM app_user WHERE id = :id',
            ['id' => $user->getId()->toRfc4122()],
        );
        if (false === $row) {
            throw ApiProblemException::notFound('Konto nicht gefunden.');
        }

        return ['state' => $row['encryption_state'], 'keyCheck' => $row['key_check']];
    }

    /**
     * Beginnt die Umstellung. Loest **vorher** alle Teilen-Beziehungen, damit
     * kein anderes Konto eine umgestellte Zeile sieht.
     *
     * Fuer dasselbe Geraet idempotent (Wiederaufnahme nach einem Abbruch).
     *
     * @return array{state: string, keyCheck: string|null}
     */
    public function begin(User $user, Uuid $clientId, string $keyCheck, \DateTimeImmutable $now): array
    {
        return $this->transactional(function (Connection $connection) use ($user, $clientId, $keyCheck, $now): array {
            $this->lock->exclusive($connection);
            [$state, $migrationClientId] = $this->lockAccount($connection, $user);

            if (EncryptionState::Encrypted === $state) {
                throw ApiProblemException::alreadyEncrypted('Das Konto ist bereits verschluesselt. Dieses Geraet braucht den Konto-Code.');
            }
            if (EncryptionState::Migrating === $state && $migrationClientId !== $clientId->toRfc4122()) {
                throw ApiProblemException::migrationInProgress('Ein anderes Geraet stellt dieses Konto gerade um.');
            }

            if (EncryptionState::Plaintext === $state) {
                $this->shares->detachForEncryption($connection, $user);
            }

            $this->setState($connection, $user, EncryptionState::Migrating, $now, $clientId, $keyCheck);

            return ['state' => EncryptionState::Migrating->value, 'keyCheck' => $keyCheck];
        });
    }

    /**
     * Schliesst die Umstellung ab, wenn keine Klartext-Zeile des Kontos mehr
     * existiert.
     *
     * Ein Konto ohne Zeilen (neu angelegt oder nach Ablauf der Frist geleert)
     * darf direkt aus `plaintext` hierher; dann ist $keyCheck Pflicht.
     *
     * @return array{state: string, keyCheck: string|null}
     */
    public function commit(User $user, Uuid $clientId, ?string $keyCheck, \DateTimeImmutable $now): array
    {
        return $this->transactional(function (Connection $connection) use ($user, $clientId, $keyCheck, $now): array {
            $this->lock->exclusive($connection);
            [$state, $migrationClientId] = $this->lockAccount($connection, $user);

            if (EncryptionState::Encrypted === $state) {
                return $this->status($user);
            }
            if (EncryptionState::Migrating === $state && $migrationClientId !== $clientId->toRfc4122()) {
                throw ApiProblemException::migrationInProgress('Ein anderes Geraet stellt dieses Konto gerade um.');
            }
            if (EncryptionState::Plaintext === $state && null === $keyCheck) {
                throw ApiProblemException::badRequest('"keyCheck" fehlt.');
            }

            $remaining = $this->countPlaintextRows($connection, $user);
            if ($remaining > 0) {
                throw ApiProblemException::encryptionIncomplete(EncryptionState::Plaintext === $state
                    ? \sprintf('Das Konto hat %d Klartext-Zeilen; zuerst "begin" aufrufen und sie verschluesselt hochladen.', $remaining)
                    : \sprintf('Noch %d Klartext-Zeilen auf dem Server.', $remaining));
            }

            // Direkter Weg ohne `begin`: auch hier keine Mitgliedschaft in
            // fremden Klartext-Listen behalten.
            if (EncryptionState::Plaintext === $state) {
                $this->shares->detachForEncryption($connection, $user);
            }

            $this->setState($connection, $user, EncryptionState::Encrypted, $now, null, $keyCheck);

            return $this->status($user);
        });
    }

    /**
     * @return array{0: EncryptionState, 1: string|null}
     */
    private function lockAccount(Connection $connection, User $user): array
    {
        /** @var array{encryption_state: string, migration_client_id: string|null}|false $row */
        $row = $connection->fetchAssociative(
            'SELECT encryption_state, migration_client_id FROM app_user WHERE id = :id FOR UPDATE',
            ['id' => $user->getId()->toRfc4122()],
        );
        if (false === $row) {
            throw ApiProblemException::notFound('Konto nicht gefunden.');
        }

        return [EncryptionState::from($row['encryption_state']), $row['migration_client_id']];
    }

    private function countPlaintextRows(Connection $connection, User $user): int
    {
        // Artikel zaehlen zum Besitzer ihrer Liste (shopping_item.user_id);
        // fremde Mitglieder gibt es hier nicht mehr (detachForEncryption).
        return (int) $connection->fetchOne(
            <<<'SQL'
                SELECT (SELECT COUNT(*) FROM shopping_list WHERE user_id = :user AND enc IS NULL)
                     + (SELECT COUNT(*) FROM shopping_item WHERE user_id = :user AND enc IS NULL)
                SQL,
            ['user' => $user->getId()->toRfc4122()],
        );
    }

    /**
     * $keyCheck `null` laesst den gespeicherten Pruefwert stehen.
     */
    private function setState(
        Connection $connection,
        User $user,
        EncryptionState $state,
        \DateTimeImmutable $now,
        ?Uuid $migrationClientId,
        ?string $keyCheck,
    ): void {
        $connection->executeStatement(
            <<<'SQL'
                UPDATE app_user
                SET encryption_state = :state,
                    encryption_changed_at = :now,
                    migration_client_id = :client,
                    key_check = COALESCE(:check, key_check)
                WHERE id = :id
                SQL,
            [
                'state' => $state->value,
                'now' => $now->format('Y-m-d H:i:s.uP'),
                'client' => $migrationClientId?->toRfc4122(),
                'check' => $keyCheck,
                'id' => $user->getId()->toRfc4122(),
            ],
        );
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

        return $this->live->around(static fn (): mixed => $connection->transactional(static fn (): mixed => $work($connection)));
    }
}
