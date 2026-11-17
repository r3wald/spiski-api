<?php

declare(strict_types=1);

namespace App\Sync\Dto;

use Symfony\Component\Uid\Uuid;

/**
 * Ergebnis des Parsens von POST /v1/sync.
 *
 * Zeilen, die schon an den Feld-Constraints scheitern, landen nicht in
 * `$lists`/`$items`, sondern direkt in `$invalidLists`/`$invalidItems`. Ihre IDs
 * stehen trotzdem in `$pushedListIds`/`$pushedItemIds`, damit der Server ihren
 * autoritativen Stand mit zurueckliefern kann (CLAUDE.md 5.2, Schritt 5).
 */
final class SyncRequest
{
    /**
     * @param list<ListChange> $lists
     * @param list<ItemChange> $items
     * @param list<PushResult> $invalidLists
     * @param list<PushResult> $invalidItems
     * @param list<Uuid>       $pushedListIds
     * @param list<Uuid>       $pushedItemIds
     */
    public function __construct(
        public readonly int $since,
        public readonly Uuid $clientId,
        public readonly int $limit,
        public readonly array $lists,
        public readonly array $items,
        public readonly array $invalidLists,
        public readonly array $invalidItems,
        public readonly array $pushedListIds,
        public readonly array $pushedItemIds,
    ) {}
}
