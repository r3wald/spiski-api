<?php

declare(strict_types=1);

namespace App\Sync;

use App\Entity\SyncView;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Vergibt Versionen aus den Zaehlern mehrerer Konten und sammelt die
 * Eintraege fuer sync_view, die flush() am Ende schreibt (CLAUDE.md 5.2, 12).
 *
 * Lebt genau eine Transaktion lang. lock() sperrt alle beteiligten
 * Kontozeilen in fester Reihenfolge (nach ID); jede Version wird unter dieser
 * Sperre vergeben, deshalb bleibt der Zaehler jedes Kontos lueckenlos und der
 * Pull kann keine Zeile ueberspringen.
 */
final class VersionPublisher
{
    /** @var array<string, int> */
    private array $counters;

    /** @var array<string, int> Zaehlerstaende beim Sperren */
    private readonly array $initial;

    /** @var array<string, array{0: string, 1: string, 2: string, 3: int}> */
    private array $upserts = [];

    /** @var list<array{0: string, 1: string, 2: list<string>}> */
    private array $removals = [];

    /**
     * @param array<string, int> $counters
     */
    private function __construct(array $counters)
    {
        $this->counters = $counters;
        $this->initial = $counters;
    }

    /**
     * @param list<string> $userIds
     */
    public static function lock(Connection $connection, array $userIds): self
    {
        $userIds = array_values(array_unique($userIds));
        sort($userIds);

        /** @var array<string, int|string> $rows */
        $rows = $connection->fetchAllKeyValue(
            'SELECT id, version_counter FROM app_user WHERE id IN (:ids) ORDER BY id FOR UPDATE',
            ['ids' => $userIds],
            ['ids' => ArrayParameterType::STRING],
        );

        return new self(array_map(intval(...), $rows));
    }

    public function counter(string $userId): int
    {
        return $this->counters[$userId] ?? throw new \LogicException('Konto ist nicht gesperrt.');
    }

    /**
     * Macht eine Zeile fuer alle Konten der Zielgruppe mit einer neuen Version
     * sichtbar.
     *
     * @param list<string> $audience
     *
     * @return array<string, int> Konto => neue Version
     */
    public function publish(string $kind, string $rowId, array $audience): array
    {
        $versions = [];
        foreach (array_unique($audience) as $userId) {
            if (!isset($this->counters[$userId])) {
                throw new \LogicException('Konto der Zielgruppe ist nicht gesperrt.');
            }

            $version = ++$this->counters[$userId];
            $this->upserts[$userId . "\0" . $kind . "\0" . $rowId] = [$userId, $kind, $rowId, $version];
            $versions[$userId] = $version;
        }

        return $versions;
    }

    /**
     * Entzieht einem Konto den Zugriff auf eine Liste: ihre Zeilen verschwinden
     * aus seiner Sicht, und der naechste Pull meldet die Liste als `revoked`.
     *
     * @param list<string> $itemIds
     */
    public function revoke(string $userId, string $listId, array $itemIds): void
    {
        $this->removals[] = [$userId, SyncView::KIND_LIST, [$listId]];
        $this->removals[] = [$userId, SyncView::KIND_ITEM, $itemIds];
        $this->publish(SyncView::KIND_REVOKED, $listId, [$userId]);
    }

    /** Hebt eine fruehere Meldung `revoked` auf (erneuter Beitritt). */
    public function unrevoke(string $userId, string $listId): void
    {
        unset($this->upserts[$userId . "\0" . SyncView::KIND_REVOKED . "\0" . $listId]);
        $this->removals[] = [$userId, SyncView::KIND_REVOKED, [$listId]];
    }

    /**
     * @return list<string> Konten, deren Zaehler gestiegen ist – sie haben
     *                      etwas abzuholen (Live-Updates, CLAUDE.md 13)
     */
    public function flush(Connection $connection): array
    {
        foreach ($this->removals as [$userId, $kind, $rowIds]) {
            if ([] === $rowIds) {
                continue;
            }
            $connection->executeStatement(
                'DELETE FROM sync_view WHERE user_id = :user AND kind = :kind AND row_id IN (:ids)',
                ['user' => $userId, 'kind' => $kind, 'ids' => $rowIds],
                ['ids' => ArrayParameterType::STRING],
            );
        }

        foreach ($this->upserts as [$userId, $kind, $rowId, $version]) {
            $connection->executeStatement(
                <<<'SQL'
                    INSERT INTO sync_view (user_id, kind, row_id, version)
                    VALUES (:user, :kind, :row, :version)
                    ON CONFLICT (user_id, kind, row_id) DO UPDATE SET version = EXCLUDED.version
                    SQL,
                ['user' => $userId, 'kind' => $kind, 'row' => $rowId, 'version' => $version],
            );
        }

        foreach ($this->counters as $userId => $counter) {
            $connection->executeStatement('UPDATE app_user SET version_counter = :counter WHERE id = :id AND version_counter < :counter', [
                'counter' => $counter,
                'id' => $userId,
            ]);
        }

        $this->upserts = [];
        $this->removals = [];

        return array_map(
            strval(...),
            array_keys(array_filter(
                $this->counters,
                fn(int $counter, string $userId): bool => $counter > $this->initial[$userId],
                \ARRAY_FILTER_USE_BOTH,
            )),
        );
    }
}
