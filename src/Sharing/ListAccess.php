<?php

declare(strict_types=1);

namespace App\Sharing;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Lese-Abfragen zu Besitz und Mitgliedschaft (CLAUDE.md 12). Bewusst DBAL: die
 * Ergebnisse steuern Sperren und Verteilung, nicht das Entity-Mapping.
 */
final class ListAccess
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Besitzer und Mitglieder der angegebenen Listen. Unbekannte IDs fehlen im
     * Ergebnis.
     *
     * @param list<string> $listIds
     *
     * @return array<string, ListAudience> indiziert nach Listen-ID
     */
    public function forLists(array $listIds): array
    {
        if ([] === $listIds) {
            return [];
        }

        /** @var list<array{id: string, user_id: string, member: string|null}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT l.id, l.user_id, m.user_id AS member
                FROM shopping_list l
                LEFT JOIN list_member m ON m.list_id = l.id
                WHERE l.id IN (:ids)
                ORDER BY l.id, m.user_id
                SQL,
            ['ids' => array_values(array_unique($listIds))],
            ['ids' => ArrayParameterType::STRING],
        );

        $owners = [];
        $members = [];
        foreach ($rows as $row) {
            $owners[$row['id']] = $row['user_id'];
            $members[$row['id']] ??= [];
            if (null !== $row['member']) {
                $members[$row['id']][] = $row['member'];
            }
        }

        $audiences = [];
        foreach ($owners as $id => $owner) {
            $audiences[$id] = new ListAudience($owner, $members[$id]);
        }

        return $audiences;
    }

    /**
     * Zu welcher Liste gehoeren diese Artikel derzeit?
     *
     * @param list<string> $itemIds
     *
     * @return array<string, string> Artikel-ID => Listen-ID
     */
    public function listIdsOfItems(array $itemIds): array
    {
        if ([] === $itemIds) {
            return [];
        }

        /** @var array<string, string> */
        return $this->connection->fetchAllKeyValue(
            'SELECT id, list_id FROM shopping_item WHERE id IN (:ids)',
            ['ids' => array_values(array_unique($itemIds))],
            ['ids' => ArrayParameterType::STRING],
        );
    }

    /**
     * @param list<string> $listIds
     *
     * @return array<string, int> Listen-ID => Zahl der Mitglieder (ohne Besitzer)
     */
    public function memberCounts(array $listIds): array
    {
        if ([] === $listIds) {
            return [];
        }

        /** @var array<string, int|string> $counts */
        $counts = $this->connection->fetchAllKeyValue(
            'SELECT list_id, COUNT(*) FROM list_member WHERE list_id IN (:ids) GROUP BY list_id',
            ['ids' => array_values(array_unique($listIds))],
            ['ids' => ArrayParameterType::STRING],
        );

        return array_map(intval(...), $counts);
    }

    /**
     * Wann dieses Konto den angegebenen Listen beigetreten ist. Eigene Listen
     * und Listen ohne Mitgliedschaft fehlen im Ergebnis.
     *
     * @param list<string> $listIds
     *
     * @return array<string, \DateTimeImmutable> Listen-ID => Beitritt
     */
    public function joinedAt(string $userId, array $listIds): array
    {
        if ([] === $listIds) {
            return [];
        }

        /** @var array<string, string> $rows */
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT list_id, joined_at FROM list_member WHERE user_id = :user AND list_id IN (:ids)',
            ['user' => $userId, 'ids' => array_values(array_unique($listIds))],
            ['ids' => ArrayParameterType::STRING],
        );

        return array_map(static fn (string $value): \DateTimeImmutable => new \DateTimeImmutable($value), $rows);
    }

    /**
     * Listen, die dieses Konto besitzt und mit anderen teilt, plus Listen, in
     * denen es Mitglied ist.
     *
     * @return list<string>
     */
    public function sharedListIdsOf(string $userId): array
    {
        /** @var list<string> */
        return $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT DISTINCT m.list_id
                FROM list_member m
                JOIN shopping_list l ON l.id = m.list_id
                WHERE m.user_id = :user OR l.user_id = :user
                SQL,
            ['user' => $userId],
        );
    }

    public function countMemberships(string $userId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM list_member WHERE user_id = :user', ['user' => $userId]);
    }

    /**
     * @return list<string>
     */
    public function itemIdsOfList(string $listId): array
    {
        /** @var list<string> */
        return $this->connection->fetchFirstColumn(
            'SELECT id FROM shopping_item WHERE list_id = :list ORDER BY server_version',
            ['list' => $listId],
        );
    }
}
