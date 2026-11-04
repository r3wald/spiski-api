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
                'lists' => array_map(fn (ShoppingList $list): array => $this->serializeList($list, $result), $result->lists),
                'items' => array_map(fn (ShoppingItem $item): array => $this->serializeItem($item, $result), $result->items),
            ],
            // Listen, auf die das Konto keinen Zugriff mehr hat (Freigabe
            // beendet, verlassen, Besitzer geloescht). Der Client entfernt sie
            // samt Artikeln, auch wenn dort noch Aenderungen ausstehen.
            'revokedLists' => $result->revokedListIds,
            'pushResults' => [
                'lists' => array_map($this->serializePushResult(...), $result->listResults),
                'items' => array_map($this->serializePushResult(...), $result->itemResults),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeList(ShoppingList $list, SyncResult $result): array
    {
        $id = $list->getId()->toRfc4122();

        return [
            'id' => $id,
            'name' => $list->getName(),
            'color' => $list->getColor(),
            'sortOrder' => $list->getSortOrder(),
            'createdAt' => $this->timestamp($list->getCreatedAt()),
            'updatedAt' => $this->timestamp($list->getUpdatedAt()),
            'deletedAt' => $this->timestamp($list->getDeletedAt()),
            'serverVersion' => $result->versions['list:'.$id] ?? $list->getServerVersion(),
            // Nur in Antworten: wem die Liste gehoert und mit wie vielen
            // Konten sie geteilt ist (CLAUDE.md 12).
            'role' => $list->getUser()->getId()->toRfc4122() === $result->viewerId ? 'owner' : 'member',
            'memberCount' => $result->memberCounts[$id] ?? 0,
            // Beitritt des abfragenden Kontos; null bei eigenen Listen.
            'joinedAt' => $this->timestamp($result->joinedAt[$id] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeItem(ShoppingItem $item, SyncResult $result): array
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
            'serverVersion' => $result->versions['item:'.$item->getId()->toRfc4122()] ?? $item->getServerVersion(),
            // Nur in Antworten: Hat zuletzt ein anderes Konto den Artikel
            // geaendert (Mitglied oder Besitzer einer geteilten Liste)? Eigene
            // Geraete zaehlen nicht. Die App benachrichtigt daraufhin.
            'changedByOther' => $this->changedByOther($item, $result),
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

    private function changedByOther(ShoppingItem $item, SyncResult $result): bool
    {
        $lastUserId = $item->getLastUserId();

        return null !== $lastUserId && '' !== $result->viewerId && $lastUserId->toRfc4122() !== $result->viewerId;
    }

    private function timestamp(?\DateTimeImmutable $value): ?string
    {
        return $value?->setTimezone(new \DateTimeZone('UTC'))->format(SyncLimits::TIMESTAMP_FORMAT);
    }
}
