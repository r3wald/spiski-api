<?php

declare(strict_types=1);

namespace App\Sync;

use App\Entity\ShoppingItem;
use App\Entity\ShoppingList;
use App\Sync\Dto\PushResult;
use App\Sync\Dto\SyncResult;

/**
 * Serialisiert das Sync-Ergebnis in die Antwortstruktur aus CLAUDE.md 5.1.
 */
final class SyncResponseSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serialize(SyncResult $result): array
    {
        return [
            'serverCursor' => $result->serverCursor,
            'hasMore' => $result->hasMore,
            'fullResync' => $result->fullResync,
            'changes' => [
                'lists' => array_map($this->serializeList(...), $result->lists),
                'items' => array_map($this->serializeItem(...), $result->items),
            ],
            'pushResults' => [
                'lists' => array_map($this->serializePushResult(...), $result->listResults),
                'items' => array_map($this->serializePushResult(...), $result->itemResults),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeList(ShoppingList $list): array
    {
        return [
            'id' => $list->getId()->toRfc4122(),
            'name' => $list->getName(),
            'color' => $list->getColor(),
            'sortOrder' => $list->getSortOrder(),
            'createdAt' => $this->timestamp($list->getCreatedAt()),
            'updatedAt' => $this->timestamp($list->getUpdatedAt()),
            'deletedAt' => $this->timestamp($list->getDeletedAt()),
            'serverVersion' => $list->getServerVersion(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeItem(ShoppingItem $item): array
    {
        return [
            'id' => $item->getId()->toRfc4122(),
            'listId' => $item->getList()->getId()->toRfc4122(),
            'name' => $item->getName(),
            'quantity' => $item->getQuantity(),
            'unit' => $item->getUnit(),
            'isChecked' => $item->isChecked(),
            'note' => $item->getNote(),
            'sortOrder' => $item->getSortOrder(),
            'createdAt' => $this->timestamp($item->getCreatedAt()),
            'updatedAt' => $this->timestamp($item->getUpdatedAt()),
            'deletedAt' => $this->timestamp($item->getDeletedAt()),
            'movedAt' => $this->timestamp($item->getMovedAt()),
            'serverVersion' => $item->getServerVersion(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePushResult(PushResult $result): array
    {
        $payload = [
            'id' => $result->id->toRfc4122(),
            'accepted' => $result->accepted,
        ];

        // "reason" nur bei Ablehnung – so ist im Client nicht zu uebersehen,
        // dass ein akzeptierter Push keinen Grund tragen kann.
        if (null !== $result->reason) {
            $payload['reason'] = $result->reason->value;
        }

        return $payload;
    }

    private function timestamp(?\DateTimeImmutable $value): ?string
    {
        return $value?->setTimezone(new \DateTimeZone('UTC'))->format(SyncLimits::TIMESTAMP_FORMAT);
    }
}
