<?php

declare(strict_types=1);

namespace App\Sync\Dto;

use App\Entity\ShoppingItem;
use App\Entity\ShoppingList;

/**
 * Ergebnis eines Sync-Durchlaufs, noch nicht serialisiert.
 */
final class SyncResult
{
    /**
     * @param list<ShoppingList> $lists
     * @param list<ShoppingItem> $items
     * @param list<PushResult>   $listResults
     * @param list<PushResult>   $itemResults
     */
    public function __construct(
        public readonly int $serverCursor,
        public readonly bool $hasMore,
        public readonly bool $fullResync,
        public readonly array $lists,
        public readonly array $items,
        public readonly array $listResults,
        public readonly array $itemResults,
    ) {
    }
}
