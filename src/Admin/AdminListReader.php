<?php

declare(strict_types=1);

namespace App\Admin;

use App\Sync\SyncLimits;
use Doctrine\DBAL\Connection;

/**
 * Lesende Uebersicht ueber alle Listen aller Accounts fuer die Admin-Seite.
 *
 * Datensparsam: Es werden Listen mit Artikelzahlen geliefert, keine Artikel. Die
 * Account-UUID ist das Zugangs-Secret des Nutzers (CLAUDE.md 6) und verlaesst
 * die Datenbank deshalb nie – stattdessen gibt es ein gehashtes Kuerzel, das nur
 * zum Gruppieren dient.
 */
final class AdminListReader
{
    private const OWNER_REF_LENGTH = 12;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param string $query Teilstring des Listennamens (ohne Beachtung der Gross-/Kleinschreibung)
     *                      oder Anfang eines Account-Kuerzels; leer = kein Filter
     *
     * @return array{
     *     total: int,
     *     ownerCount: int,
     *     limit: int,
     *     offset: int,
     *     lists: list<array<string, mixed>>
     * }
     */
    public function read(int $limit, int $offset, bool $includeDeleted, string $query = ''): array
    {
        $conditions = [];
        $params = [];

        if (!$includeDeleted) {
            $conditions[] = 'l.deleted_at IS NULL';
        }

        if ('' !== $query) {
            // Das Kuerzel wird in der Datenbank mit demselben Verfahren gebildet wie in
            // ownerRef(): SHA-256 der kanonischen (kleingeschriebenen) UUID.
            $conditions[] = <<<'SQL'
                (l.name ILIKE :like ESCAPE '\'
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
                           COUNT(i.id) FILTER (WHERE i.deleted_at IS NULL) AS active_item_count
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
            $lists[] = [
                'id' => (string) $row['id'],
                'owner' => $this->ownerRef((string) $row['user_id']),
                'name' => (string) $row['name'],
                'color' => (int) $row['color'],
                'sortOrder' => (int) $row['sort_order'],
                'createdAt' => $this->timestamp((string) $row['created_at']),
                'updatedAt' => $this->timestamp((string) $row['updated_at']),
                'deletedAt' => null === $row['deleted_at'] ? null : $this->timestamp((string) $row['deleted_at']),
                'itemCount' => (int) $row['item_count'],
                'activeItemCount' => (int) $row['active_item_count'],
            ];
        }

        return [
            'total' => false === $totals ? 0 : (int) $totals['total'],
            'ownerCount' => false === $totals ? 0 : (int) $totals['owners'],
            'limit' => $limit,
            'offset' => $offset,
            'lists' => $lists,
        ];
    }

    private function ownerRef(string $accountId): string
    {
        return substr(hash('sha256', $accountId), 0, self::OWNER_REF_LENGTH);
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
