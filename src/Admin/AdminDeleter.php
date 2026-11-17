<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\SyncView;
use App\Exception\ApiProblemException;
use App\Live\LiveUpdates;
use App\Sharing\ListAccess;
use App\Sync\SyncLimits;
use App\Sync\SyncLock;
use App\Sync\VersionPublisher;
use Doctrine\DBAL\Connection;

/**
 * Loescht Eintraege (Artikel) und ganze Listen von der Admin-Seite aus.
 *
 * Loeschen heisst hier dasselbe wie in der App: Tombstone (`deleted_at`), kein
 * hartes DELETE. Eine hart geloeschte Zeile kaeme beim naechsten Push eines Geraets
 * als unbekannte Zeile zurueck (Insert, CLAUDE.md 5.3); der Tombstone dagegen geht
 * ueber den Pull an alle Konten, die die Liste sehen, und verschwindet dort.
 *
 * Eine Liste wird wie in der App geloescht: nur die Liste bekommt den Tombstone,
 * ihre Artikel bleiben unveraendert (die App blendet sie mit der Liste aus). Der
 * Besitzer findet sie danach im Archiv der App und kann sie wiederherstellen.
 * Mitgliedschaften und Teilen-Code bleiben – auch das wie in der App.
 *
 * Damit sich der Tombstone durchsetzt:
 * - neue Version in **jedem** Konto der Zielgruppe (Besitzer + Mitglieder, CLAUDE.md 12),
 *   unter denselben Sperren wie ein Sync;
 * - `updated_at` strikt nach dem bisherigen Stand, damit ein Geraet mit dem alten
 *   Stand den Last-Write-Wins-Vergleich verliert;
 * - `last_client_id` = groesste UUID: Bei gleichem `updatedAt` gewinnt der Tombstone;
 * - bei Artikeln `last_user_id` = null: kein Konto hat geaendert, `changedByOther` bleibt aus;
 * - Live-Update an alle betroffenen Konten nach dem Commit (CLAUDE.md 13).
 *
 * Verschluesselte Zeilen lassen sich genauso loeschen: `deletedAt` ist lesbar,
 * `enc` bleibt unberuehrt (CLAUDE.md 14).
 */
final class AdminDeleter
{
    public const ADMIN_CLIENT_ID = 'ffffffff-ffff-ffff-ffff-ffffffffffff';

    public function __construct(
        private readonly Connection $connection,
        private readonly ListAccess $access,
        private readonly SyncLock $lock,
        private readonly LiveUpdates $live,
    ) {}

    /**
     * @return bool true = geloescht, false = war schon archiviert (nichts geaendert)
     */
    public function deleteItem(string $itemId, \DateTimeImmutable $now): bool
    {
        return $this->transactional(function () use ($itemId, $now): bool {
            /** @var string|false $listId */
            $listId = $this->connection->fetchOne('SELECT list_id FROM shopping_item WHERE id = :id', [
                'id' => $itemId,
            ]);
            if (false === $listId) {
                throw ApiProblemException::notFound('Eintrag nicht gefunden.');
            }

            return $this->tombstone('shopping_item', SyncView::KIND_ITEM, $itemId, $listId, $now);
        });
    }

    /**
     * @return bool true = geloescht, false = war schon geloescht (nichts geaendert)
     */
    public function deleteList(string $listId, \DateTimeImmutable $now): bool
    {
        return $this->transactional(function () use ($listId, $now): bool {
            if (
                false === $this->connection->fetchOne('SELECT 1 FROM shopping_list WHERE id = :id', ['id' => $listId])
            ) {
                throw ApiProblemException::notFound('Liste nicht gefunden.');
            }

            return $this->tombstone('shopping_list', SyncView::KIND_LIST, $listId, $listId, $now);
        });
    }

    /**
     * @param callable(): bool $work
     */
    private function transactional(callable $work): bool
    {
        /** @var mixed $result */
        $result = $this->live->around(fn(): mixed => $this->connection->transactional(function () use ($work): bool {
            // Selten und womoeglich an einer geteilten Liste: exklusiv, wie Beitritt
            // und Austritt. Danach sind die Mitgliedschaften stabil.
            $this->lock->exclusive($this->connection);

            return $work();
        }));

        return true === $result;
    }

    /**
     * @param 'shopping_item'|'shopping_list' $table
     */
    private function tombstone(
        string $table,
        string $kind,
        string $rowId,
        string $listId,
        \DateTimeImmutable $now,
    ): bool {
        $audience = $this->access->forLists([$listId])[$listId] ?? throw new \LogicException(
            'Liste einer vorhandenen Zeile fehlt.',
        );

        // Sperrt die Kontozeilen; ein Sync dieser Konten wartet jetzt. Erst danach
        // ist der Stand der Zeile verbindlich.
        $publisher = VersionPublisher::lock($this->connection, $audience->all());

        /** @var array{updated_at: string, deleted_at: ?string}|false $row */
        $row = $this->connection->fetchAssociative(
            \sprintf('SELECT updated_at, deleted_at FROM %s WHERE id = :id FOR UPDATE', $table),
            ['id' => $rowId],
        );
        if (false === $row) {
            throw ApiProblemException::notFound('Nicht gefunden.');
        }
        if (null !== $row['deleted_at']) {
            return false;
        }

        $at = $this->after($now, $row['updated_at']);
        $versions = $publisher->publish($kind, $rowId, $audience->all());

        $this->connection->executeStatement(
            \sprintf(
                'UPDATE %s SET deleted_at = :at, updated_at = :at, server_version = :version, last_client_id = :client%s WHERE id = :id',
                $table,
                'shopping_item' === $table ? ', last_user_id = NULL' : '',
            ),
            [
                'at' => $at->format('Y-m-d H:i:s.vP'),
                'version' => $versions[$audience->owner],
                'client' => self::ADMIN_CLIENT_ID,
                'id' => $rowId,
            ],
        );

        $this->live->queue($publisher->flush($this->connection));

        return true;
    }

    /**
     * Zeitpunkt des Tombstones: jetzt, aber strikt nach dem bisherigen Stand. Lag die
     * Uhr eines Geraets vor (bis zur Kappungsgrenze erlaubt), eine Millisekunde danach,
     * sonst gewaenne der alte Stand. Auf Millisekunden gerundet wie alle Zeitstempel.
     */
    private function after(\DateTimeImmutable $now, string $previousUpdatedAt): \DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        $toMs = static function (\DateTimeImmutable $t) use ($utc): \DateTimeImmutable {
            $rounded = \DateTimeImmutable::createFromFormat(
                SyncLimits::TIMESTAMP_FORMAT,
                $t->setTimezone($utc)->format(SyncLimits::TIMESTAMP_FORMAT),
                $utc,
            );
            if (false === $rounded) {
                throw new \LogicException('Zeitstempel nicht darstellbar.');
            }

            return $rounded;
        };

        // Erst runden, dann vergleichen: sonst koennte das Runden einen Gleichstand erzeugen.
        $at = $toMs($now);
        $previous = $toMs(new \DateTimeImmutable($previousUpdatedAt));

        return $at > $previous ? $at : $previous->modify('+1 millisecond');
    }
}
