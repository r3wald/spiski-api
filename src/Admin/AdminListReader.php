<?php

declare(strict_types=1);

namespace App\Admin;

use App\Sync\SyncLimits;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Lesende Uebersicht ueber alle Listen aller Accounts (und auf Wunsch deren
 * Eintraege) fuer die Admin-Seite.
 *
 * Die Account-UUID ist das Zugangs-Secret des Nutzers (CLAUDE.md 6) und verlaesst
 * die Datenbank nie – stattdessen gibt es ein gehashtes Kuerzel, das nur zum
 * Gruppieren dient. Dasselbe gilt fuer den Teilen-Code einer Liste (CLAUDE.md 12):
 * ausgeliefert wird nur, ob er aktiv ist. Listennamen, Eintraege und Notizen sind
 * Nutzerinhalt.
 *
 * Verschluesselte Zeilen (CLAUDE.md 14) kann der Server nicht lesen: `name` ist
 * dann `null` und `encrypted` `true`; der Chiffretext selbst wird nicht
 * ausgeliefert. Je Liste steht der Zustand des Besitzerkontos in
 * `encryptionState`.
 */
final class AdminListReader
{
    private const OWNER_REF_LENGTH = 12;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param string $query                Teilstring des Listennamens (ohne Beachtung der Gross-/Kleinschreibung,
     *                                     nur unverschluesselte Listen) oder Anfang eines Account-Kuerzels; leer = kein Filter
     * @param bool   $includeItems         die Eintraege jeder Liste unter `items` mitliefern
     * @param bool   $includeArchivedItems dabei auch archivierte (soft-geloeschte) Eintraege
     *
     * @return array{
     *     total: int,
     *     ownerCount: int,
     *     limit: int,
     *     offset: int,
     *     lists: list<array<string, mixed>>
     * }
     */
    public function read(
        int $limit,
        int $offset,
        bool $includeDeleted,
        string $query = '',
        bool $includeItems = false,
        bool $includeArchivedItems = false,
    ): array {
        $conditions = [];
        $params = [];

        if (!$includeDeleted) {
            $conditions[] = 'l.deleted_at IS NULL';
        }

        if ('' !== $query) {
            // Das Kuerzel wird in der Datenbank mit demselben Verfahren gebildet wie in
            // ownerRef(): SHA-256 der kanonischen (kleingeschriebenen) UUID.
            $conditions[] = <<<'SQL'
                ((l.enc IS NULL AND l.name ILIKE :like ESCAPE '\')
                 OR left(encode(sha256(convert_to(l.user_id::text, 'UTF8')), 'hex'), 12) LIKE :owner ESCAPE '\')
                SQL;
            $params['like'] = '%'.$this->escapeLike($query).'%';
            $params['owner'] = $this->escapeLike(strtolower($query)).'%';
        }

        $where = [] === $conditions ? '' : 'WHERE '.implode(' AND ', $conditions);

        /** @var array{total: int|string, owners: int|string}|false $totals */
        $totals = $this->connection->fetchAssociative(
            \sprintf('SELECT COUNT(*) AS total, COUNT(DISTINCT l.user_id) AS owners FROM shopping_list l %s', $where),
            $params,
        );

        // GROUP BY auf den Primaerschluessel reicht in PostgreSQL: alle anderen
        // Spalten der Liste sind davon funktional abhaengig.
        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                <<<'SQL'
                    SELECT l.id, l.user_id, l.name, l.color, l.sort_order,
                           l.created_at, l.updated_at, l.deleted_at,
                           COUNT(i.id) AS item_count,
                           COUNT(i.id) FILTER (WHERE i.deleted_at IS NULL) AS active_item_count,
                           -- Unterabfrage statt zweitem JOIN: ein JOIN auf list_member wuerde jede
                           -- Artikelzeile je Mitglied vervielfachen und die Zaehler verfaelschen.
                           (SELECT COUNT(*) FROM list_member m WHERE m.list_id = l.id) AS member_count,
                           -- Nur ob ein Code aktiv ist, nie der Code selbst: wer ihn kennt, kann beitreten.
                           (l.share_code IS NOT NULL) AS share_code_active,
                           (l.enc IS NOT NULL) AS encrypted,
                           (SELECT u.encryption_state FROM app_user u WHERE u.id = l.user_id) AS encryption_state
                    FROM shopping_list l
                    LEFT JOIN shopping_item i ON i.list_id = l.id
                    %s
                    GROUP BY l.id
                    ORDER BY l.updated_at DESC, l.id
                    LIMIT :limit OFFSET :offset
                    SQL,
                $where,
            ),
            $params + ['limit' => $limit, 'offset' => $offset],
        );

        $lists = [];
        foreach ($rows as $row) {
            $encrypted = $this->toBool($row['encrypted']);
            $lists[] = [
                'id' => (string) $row['id'],
                'owner' => $this->ownerRef((string) $row['user_id']),
                'encryptionState' => (string) $row['encryption_state'],
                'encrypted' => $encrypted,
                'name' => $encrypted ? null : (string) $row['name'],
                'color' => (int) $row['color'],
                'sortOrder' => (int) $row['sort_order'],
                'createdAt' => $this->timestamp((string) $row['created_at']),
                'updatedAt' => $this->timestamp((string) $row['updated_at']),
                'deletedAt' => null === $row['deleted_at'] ? null : $this->timestamp((string) $row['deleted_at']),
                'itemCount' => (int) $row['item_count'],
                'activeItemCount' => (int) $row['active_item_count'],
            ] + $this->sharing((int) $row['member_count'], $this->toBool($row['share_code_active']));
        }

        if ($includeItems && [] !== $lists) {
            $itemsByList = $this->itemsByList(array_column($lists, 'id'), $includeArchivedItems);
            foreach ($lists as $index => $list) {
                $lists[$index]['items'] = $itemsByList[$list['id']] ?? [];
            }
        }

        return [
            'total' => false === $totals ? 0 : (int) $totals['total'],
            'ownerCount' => false === $totals ? 0 : (int) $totals['owners'],
            'limit' => $limit,
            'offset' => $offset,
            'lists' => $lists,
        ];
    }

    /**
     * Eine einzige Abfrage fuer alle Listen der Seite statt einer je Liste.
     *
     * @param list<string> $listIds
     *
     * @return array<string, list<array<string, mixed>>> Eintraege je Listen-ID, in der Reihenfolge der App
     */
    private function itemsByList(array $listIds, bool $includeArchived): array
    {
        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                <<<'SQL'
                    SELECT id, list_id, name, quantity, unit, is_checked, note, sort_order,
                           created_at, updated_at, deleted_at, moved_at, (enc IS NOT NULL) AS encrypted
                    FROM shopping_item
                    WHERE list_id IN (:ids) %s
                    ORDER BY list_id, sort_order, created_at, id
                    SQL,
                $includeArchived ? '' : 'AND deleted_at IS NULL',
            ),
            ['ids' => $listIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $byList = [];
        foreach ($rows as $row) {
            $encrypted = $this->toBool($row['encrypted']);
            $byList[(string) $row['list_id']][] = [
                'id' => (string) $row['id'],
                'encrypted' => $encrypted,
                'name' => $encrypted ? null : (string) $row['name'],
                'quantity' => null === $row['quantity'] ? null : (float) $row['quantity'],
                'unit' => null === $row['unit'] ? null : (string) $row['unit'],
                'isChecked' => $this->toBool($row['is_checked']),
                'note' => null === $row['note'] ? null : (string) $row['note'],
                'sortOrder' => (int) $row['sort_order'],
                'createdAt' => $this->timestamp((string) $row['created_at']),
                'updatedAt' => $this->timestamp((string) $row['updated_at']),
                'deletedAt' => null === $row['deleted_at'] ? null : $this->timestamp((string) $row['deleted_at']),
                'movedAt' => null === $row['moved_at'] ? null : $this->timestamp((string) $row['moved_at']),
            ];
        }

        return $byList;
    }

    /**
     * Geteilt heisst: mindestens ein Mitglied (wie auf der Statusseite). Der
     * Besitzer ist kein Mitglied, darf die Liste aber natuerlich sehen: Zugriff
     * haben `memberCount + 1` Konten. Ein aktiver Code ohne Mitglied ist
     * vorbereitet, aber noch nicht geteilt.
     *
     * @return array{shared: bool, memberCount: int, userCount: int, shareCodeActive: bool}
     */
    private function sharing(int $memberCount, bool $shareCodeActive): array
    {
        return [
            'shared' => $memberCount > 0,
            'memberCount' => $memberCount,
            'userCount' => $memberCount + 1,
            'shareCodeActive' => $shareCodeActive,
        ];
    }

    private function ownerRef(string $accountId): string
    {
        return substr(hash('sha256', $accountId), 0, self::OWNER_REF_LENGTH);
    }

    /**
     * PostgreSQL liefert Booleans je nach Treiber als bool oder als 't'/'f'. Ein
     * schlichter Cast machte aus 'f' ein true.
     */
    private function toBool(mixed $value): bool
    {
        return \in_array($value, [true, 't', 'true', '1', 1], true);
    }

    /**
     * Macht aus Nutzereingabe ein LIKE-Muster ohne Platzhalter: `%`, `_` und der
     * Escape-Backslash selbst gelten woertlich.
     */
    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    private function timestamp(string $value): string
    {
        return (new \DateTimeImmutable($value))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(SyncLimits::TIMESTAMP_FORMAT);
    }
}
