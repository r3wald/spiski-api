<?php

declare(strict_types=1);

namespace App\Admin;

use App\Sync\SyncLimits;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Lesende Uebersicht ueber alle Listen aller Accounts (und auf Wunsch deren
 * Eintraege) fuer die Admin-Oberflaeche (`admin/`, Vue).
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

    /**
     * Das Kuerzel in SQL, mit demselben Verfahren wie ownerRef(): SHA-256 der
     * kanonischen (kleingeschriebenen) UUID, die ersten 12 Hex-Zeichen.
     */
    private const OWNER_REF_SQL = "left(encode(sha256(convert_to(l.user_id::text, 'UTF8')), 'hex'), 12)";

    /**
     * Sortierschluessel => SQL. Nur diese Werte kommen in ORDER BY; der Controller
     * laesst keine anderen durch. Verschluesselte Namen sind nicht lesbar und
     * stehen beim Sortieren nach Namen immer am Ende.
     */
    private const SORT_SQL = [
        'updatedAt' => 'l.updated_at',
        'createdAt' => 'l.created_at',
        'name' => 'CASE WHEN l.enc IS NULL THEN lower(l.name) END',
        'owner' => self::OWNER_REF_SQL,
        'items' => 'active_item_count',
        'users' => 'member_count',
    ];

    /**
     * GROUP BY auf die Primaerschluessel reicht in PostgreSQL: alle anderen Spalten
     * von Liste und Konto sind davon funktional abhaengig.
     */
    private const LIST_SQL = <<<'SQL'
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
               u.encryption_state
        FROM shopping_list l
        JOIN app_user u ON u.id = l.user_id
        LEFT JOIN shopping_item i ON i.list_id = l.id
        %s
        GROUP BY l.id, u.id
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * @return array{
     *     total: int,
     *     ownerCount: int,
     *     limit: int,
     *     offset: int,
     *     lists: list<array<string, mixed>>
     * }
     */
    public function read(AdminListQuery $query): array
    {
        [$conditions, $params] = $this->conditions($query);
        $where = [] === $conditions ? '' : 'WHERE ' . implode(' AND ', $conditions);

        /** @var array{total: int|string, owners: int|string}|false $totals */
        $totals = $this->connection->fetchAssociative(\sprintf(
            'SELECT COUNT(*) AS total, COUNT(DISTINCT l.user_id) AS owners
                 FROM shopping_list l JOIN app_user u ON u.id = l.user_id %s',
            $where,
        ), $params);

        $direction = 'asc' === $query->direction ? 'ASC' : 'DESC';
        $rows = $this->connection->fetchAllAssociative(
            \sprintf(self::LIST_SQL, $where)
                . \sprintf(
                    ' ORDER BY %s %s NULLS LAST, l.updated_at DESC, l.id LIMIT :limit OFFSET :offset',
                    self::SORT_SQL[$query->sort] ?? self::SORT_SQL['updatedAt'],
                    $direction,
                ),
            $params + ['limit' => $query->limit, 'offset' => $query->offset],
        );

        $lists = array_map($this->mapList(...), $rows);

        if ($query->includeItems && [] !== $lists) {
            $itemsByList = $this->itemsByList(array_column($lists, 'id'), $query->includeArchivedItems);
            foreach ($lists as $index => $list) {
                $lists[$index]['items'] = $itemsByList[$list['id']] ?? [];
            }
        }

        return [
            'total' => false === $totals ? 0 : (int) $totals['total'],
            'ownerCount' => false === $totals ? 0 : (int) $totals['owners'],
            'limit' => $query->limit,
            'offset' => $query->offset,
            'lists' => $lists,
        ];
    }

    /**
     * Eine Liste fuer die Detailseite: alle Felder der Uebersicht, dazu die Zahl der
     * Listen desselben Accounts und alle Eintraege, archivierte eingeschlossen.
     *
     * @return array<string, mixed>|null
     */
    public function detail(string $listId): ?array
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->connection->fetchAssociative(\sprintf(self::LIST_SQL, 'WHERE l.id = :id'), ['id' => $listId]);
        if (false === $row) {
            return null;
        }

        $list = $this->mapList($row);
        $list['ownerListCount'] =
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM shopping_list WHERE user_id = :owner', [
                'owner' => (string) $row['user_id'],
            ]);
        $list['items'] = $this->itemsByList([$listId], true)[$listId] ?? [];

        return $list;
    }

    /**
     * Alle Accounts, denen mindestens eine Liste gehoert (geloeschte eingeschlossen),
     * fuer die Auswahl des Eigentuemer-Filters. Nur das Kuerzel, nie die UUID.
     * Sortiert nach Kuerzel, damit die Reihenfolge stabil und nachschlagbar bleibt.
     *
     * @return list<array{owner: string, encryptionState: string, listCount: int, activeListCount: int}>
     */
    public function owners(): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT l.user_id, u.encryption_state,
                   COUNT(*) AS list_count,
                   COUNT(*) FILTER (WHERE l.deleted_at IS NULL) AS active_list_count
            FROM shopping_list l
            JOIN app_user u ON u.id = l.user_id
            GROUP BY l.user_id, u.id
            SQL);

        $owners = array_map(
            fn(array $row): array => [
                'owner' => $this->ownerRef((string) $row['user_id']),
                'encryptionState' => (string) $row['encryption_state'],
                'listCount' => (int) $row['list_count'],
                'activeListCount' => (int) $row['active_list_count'],
            ],
            $rows,
        );
        usort($owners, static fn(array $a, array $b): int => $a['owner'] <=> $b['owner']);

        return $owners;
    }

    /**
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private function conditions(AdminListQuery $query): array
    {
        $conditions = [];
        $params = [];

        if (AdminListQuery::STATUS_ACTIVE === $query->status) {
            $conditions[] = 'l.deleted_at IS NULL';
        } elseif (AdminListQuery::STATUS_DELETED === $query->status) {
            $conditions[] = 'l.deleted_at IS NOT NULL';
        }

        if ('' !== $query->query) {
            $conditions[] = \sprintf(
                "((l.enc IS NULL AND l.name ILIKE :like ESCAPE '\\') OR %s LIKE :ownerQuery ESCAPE '\\')",
                self::OWNER_REF_SQL,
            );
            $params['like'] = '%' . $this->escapeLike($query->query) . '%';
            $params['ownerQuery'] = $this->escapeLike(strtolower($query->query)) . '%';
        }

        if ('' !== $query->owner) {
            // Nur Hex-Ziffern (Controller), also ohne LIKE-Sonderzeichen.
            $conditions[] = self::OWNER_REF_SQL . ' LIKE :owner';
            $params['owner'] = $query->owner . '%';
        }

        if (null !== $query->encryption) {
            $conditions[] = 'u.encryption_state = :encryption';
            $params['encryption'] = $query->encryption;
        }

        $ageColumn = AdminListQuery::AGE_UPDATED === $query->ageField ? 'l.updated_at' : 'l.created_at';
        if (null !== $query->olderThan) {
            $conditions[] = $ageColumn . ' <= :olderThan';
            $params['olderThan'] = $query->olderThan->format('Y-m-d\TH:i:s.uP');
        }
        if (null !== $query->newerThan) {
            $conditions[] = $ageColumn . ' >= :newerThan';
            $params['newerThan'] = $query->newerThan->format('Y-m-d\TH:i:s.uP');
        }

        return [$conditions, $params];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function mapList(array $row): array
    {
        $encrypted = $this->toBool($row['encrypted']);

        return (
            [
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
            ] + $this->sharing((int) $row['member_count'], $this->toBool($row['share_code_active']))
        );
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
        $rows = $this->connection->fetchAllAssociative(\sprintf(<<<'SQL'
            SELECT id, list_id, name, quantity, unit, is_checked, note, sort_order,
                   created_at, updated_at, deleted_at, moved_at, (enc IS NOT NULL) AS encrypted
            FROM shopping_item
            WHERE list_id IN (:ids)%s
            ORDER BY list_id, sort_order, created_at, id
            SQL, $includeArchived
            ? ''
            : ' AND deleted_at IS NULL'), ['ids' => $listIds], ['ids' => ArrayParameterType::STRING]);

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
        return new \DateTimeImmutable($value)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(SyncLimits::TIMESTAMP_FORMAT);
    }
}
