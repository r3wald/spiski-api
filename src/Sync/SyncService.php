<?php

declare(strict_types=1);

namespace App\Sync;

use App\Entity\ShoppingItem;
use App\Entity\ShoppingList;
use App\Entity\SyncView;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Repository\ShoppingItemRepository;
use App\Repository\ShoppingListRepository;
use App\Sharing\ListAccess;
use App\Sharing\ListAudience;
use App\Sync\Dto\ItemChange;
use App\Sync\Dto\ListChange;
use App\Sync\Dto\PushResult;
use App\Sync\Dto\RejectReason;
use App\Sync\Dto\SyncRequest;
use App\Sync\Dto\SyncResult;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Push und Pull in einer einzigen Transaktion (CLAUDE.md 5.2), mit geteilten
 * Listen (CLAUDE.md 12).
 */
final class SyncService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShoppingListRepository $listRepository,
        private readonly ShoppingItemRepository $itemRepository,
        private readonly ConflictResolver $conflictResolver,
        private readonly ListAccess $access,
        private readonly SyncLock $lock,
    ) {
    }

    public function sync(User $user, SyncRequest $request, \DateTimeImmutable $now): SyncResult
    {
        /** @var mixed $result */
        $result = $this->em->wrapInTransaction(fn (): SyncResult => $this->run($user, $request, $now));
        \assert($result instanceof SyncResult);

        return $result;
    }

    private function run(User $user, SyncRequest $request, \DateTimeImmutable $now): SyncResult
    {
        $connection = $this->em->getConnection();
        $userId = $user->getId()->toRfc4122();

        // Welche Listen beruehrt der Push? Gehoert eine davon einem anderen
        // Konto oder hat sie Mitglieder, braucht es die exklusive Sperre.
        $itemLists = $this->access->listIdsOfItems(array_map(static fn (ItemChange $c): string => $c->id->toRfc4122(), $request->items));
        $touched = array_values(array_unique([
            ...array_map(static fn (ListChange $c): string => $c->id->toRfc4122(), $request->lists),
            ...array_map(static fn (ItemChange $c): string => $c->listId->toRfc4122(), $request->items),
            ...array_values($itemLists),
        ]));

        $exclusive = $this->needsExclusive($userId, $this->access->forLists($touched));
        $exclusive ? $this->lock->exclusive($connection) : $this->lock->shared($connection);

        // Unter der Sperre neu lesen: erst jetzt sind die Mitgliedschaften stabil.
        $audiences = $this->access->forLists($touched);
        if (!$exclusive && $this->needsExclusive($userId, $audiences)) {
            // Zwischen der ersten Abfrage und der Sperre wurde eine Liste
            // geteilt. Ein Hochstufen der Sperre koennte verklemmen.
            throw ApiProblemException::retryLater('Freigabe wurde gerade geaendert, bitte erneut synchronisieren.');
        }

        $userIds = [$userId];
        foreach ($audiences as $audience) {
            array_push($userIds, ...$audience->all());
        }
        $publisher = VersionPublisher::lock($connection, $exclusive ? $userIds : [$userId]);

        $counter = $publisher->counter($userId);

        // Cursor aus einer geloeschten oder wiederhergestellten Datenbank: der
        // Client kennt eine Version, die es serverseitig nicht (mehr) gibt.
        $fullResync = $request->since > $counter;
        $since = $fullResync ? 0 : $request->since;

        $listResults = $request->invalidLists;
        $itemResults = $request->invalidItems;

        $pushedLists = $this->pushLists($user, $request, $publisher, $audiences, $listResults);
        $this->pushItems($user, $request, $publisher, $audiences, $pushedLists, $itemResults);

        $publisher->flush($connection);
        $user->setLastSeenAt($now);

        // Muss vor dem Pull passieren, sonst sieht die Abfrage die soeben
        // vergebenen Versionen nicht.
        $this->em->flush();

        return $this->pull($userId, $request, $since, $publisher->counter($userId), $fullResync, $listResults, $itemResults);
    }

    /**
     * @param array<string, ListAudience> $audiences
     */
    private function needsExclusive(string $userId, array $audiences): bool
    {
        foreach ($audiences as $audience) {
            if ($audience->owner !== $userId || $audience->isShared()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, ListAudience> $audiences
     * @param list<PushResult>            $listResults
     *
     * @return array<string, ShoppingList> die gepushten Listen, auch neu angelegte
     */
    private function pushLists(User $user, SyncRequest $request, VersionPublisher $publisher, array &$audiences, array &$listResults): array
    {
        $userId = $user->getId()->toRfc4122();
        $existingLists = $this->listRepository->findByIds(array_map(static fn (ListChange $c): Uuid => $c->id, $request->lists));
        $listCount = null;

        foreach ($request->lists as $change) {
            $key = $change->id->toRfc4122();
            $existing = $existingLists[$key] ?? null;

            // Die Liste selbst (Name, Farbe, Reihenfolge, Loeschen) aendert
            // nur der Besitzer; Mitglieder bearbeiten nur die Artikel.
            if (null !== $existing && $existing->getUser()->getId()->toRfc4122() !== $userId) {
                $listResults[] = PushResult::rejected($change->id, RejectReason::Forbidden);
                continue;
            }

            $decision = null === $existing
                ? MergeDecision::Insert
                : $this->conflictResolver->decide(
                    $existing->getUpdatedAt(),
                    $existing->getLastClientId(),
                    $change->updatedAt,
                    $request->clientId,
                );

            if (MergeDecision::Reject === $decision) {
                $listResults[] = PushResult::rejected($change->id, RejectReason::Stale);
                continue;
            }

            if (MergeDecision::NoOp === $decision) {
                $listResults[] = PushResult::accepted($change->id);
                continue;
            }

            if (MergeDecision::Insert === $decision) {
                $listCount ??= $this->listRepository->countForUser($user);
                if ($listCount >= SyncLimits::MAX_LISTS_PER_USER) {
                    $listResults[] = PushResult::rejected($change->id, RejectReason::QuotaExceeded);
                    continue;
                }

                $existing = new ShoppingList($change->id, $user);
                $this->em->persist($existing);
                $existingLists[$key] = $existing;
                $audiences[$key] = new ListAudience($userId);
                ++$listCount;
            }

            \assert($existing instanceof ShoppingList);
            $versions = $publisher->publish(SyncView::KIND_LIST, $key, $audiences[$key]->all());
            $existing->apply($change, $versions[$userId], $request->clientId);
            $listResults[] = PushResult::accepted($change->id);
        }

        return $existingLists;
    }

    /**
     * @param array<string, ListAudience> $audiences
     * @param array<string, ShoppingList> $pushedLists
     * @param list<PushResult>            $itemResults
     */
    private function pushItems(User $user, SyncRequest $request, VersionPublisher $publisher, array $audiences, array $pushedLists, array &$itemResults): void
    {
        $userId = $user->getId()->toRfc4122();
        $existingItems = $this->itemRepository->findByIds(array_map(static fn (ItemChange $c): Uuid => $c->id, $request->items));

        // Alle beteiligten Listen in einer Abfrage: Ziel-Listen der Artikel und
        // die Listen, in denen bestehende Artikel derzeit liegen.
        $listIds = array_map(static fn (ItemChange $c): string => $c->listId->toRfc4122(), $request->items);
        foreach ($existingItems as $item) {
            $listIds[] = $item->getList()->getId()->toRfc4122();
        }
        // Im selben Request neu angelegte Listen sind noch nicht geschrieben und
        // wuerden von der Abfrage nicht gefunden.
        $missing = array_values(array_diff(array_unique($listIds), array_keys($pushedLists)));
        $lists = $pushedLists + $this->listRepository->findByIds($missing);

        /** @var array<string, int> $itemCounts Besitzer => Artikel */
        $itemCounts = [];

        foreach ($request->items as $change) {
            $listKey = $change->listId->toRfc4122();
            $list = $lists[$listKey] ?? null;
            $audience = $audiences[$listKey] ?? null;

            // Nur Listen, die das Konto besitzt oder in denen es Mitglied ist.
            // Eine fremde listId findet nichts; das Schreiben in fremde Listen
            // ist damit strukturell ausgeschlossen (CLAUDE.md 5.2, 12).
            if (null === $list || null === $audience || !$audience->includes($userId)) {
                $itemResults[] = PushResult::rejected($change->id, RejectReason::UnknownList);
                continue;
            }

            $key = $change->id->toRfc4122();
            $existing = $existingItems[$key] ?? null;

            if (null !== $existing) {
                $currentKey = $existing->getList()->getId()->toRfc4122();
                $current = $audiences[$currentKey] ?? null;
                if (null === $current || !$current->includes($userId)) {
                    $itemResults[] = PushResult::rejected($change->id, RejectReason::UnknownList);
                    continue;
                }

                // Ein Artikel darf nur zwischen Listen mit denselben Teilnehmern
                // wandern, sonst saehe ihn jemand weiter, der keinen Zugriff
                // mehr haben soll.
                if ($currentKey !== $listKey && !$current->sameAs($audience)) {
                    $itemResults[] = PushResult::rejected($change->id, RejectReason::Invalid);
                    continue;
                }
            }

            $decision = null === $existing
                ? MergeDecision::Insert
                : $this->conflictResolver->decide(
                    $existing->getUpdatedAt(),
                    $existing->getLastClientId(),
                    $change->updatedAt,
                    $request->clientId,
                );

            if (MergeDecision::Reject === $decision) {
                $itemResults[] = PushResult::rejected($change->id, RejectReason::Stale);
                continue;
            }

            if (MergeDecision::NoOp === $decision) {
                $itemResults[] = PushResult::accepted($change->id);
                continue;
            }

            if (MergeDecision::Insert === $decision) {
                // Das Kontingent zaehlt beim Besitzer der Liste.
                $owner = $list->getUser();
                $ownerKey = $owner->getId()->toRfc4122();
                $itemCounts[$ownerKey] ??= $this->itemRepository->countForUser($owner);
                if ($itemCounts[$ownerKey] >= SyncLimits::MAX_ITEMS_PER_USER) {
                    $itemResults[] = PushResult::rejected($change->id, RejectReason::QuotaExceeded);
                    continue;
                }

                $existing = new ShoppingItem($change->id, $owner, $list);
                $this->em->persist($existing);
                $existingItems[$key] = $existing;
                ++$itemCounts[$ownerKey];
            }

            \assert($existing instanceof ShoppingItem);
            $versions = $publisher->publish(SyncView::KIND_ITEM, $key, $audience->all());
            $existing->apply($change, $list, $versions[$audience->owner], $request->clientId, $user->getId());
            $itemResults[] = PushResult::accepted($change->id);
        }
    }

    /**
     * @param list<PushResult> $listResults
     * @param list<PushResult> $itemResults
     */
    private function pull(
        string $userId,
        SyncRequest $request,
        int $since,
        int $counter,
        bool $fullResync,
        array $listResults,
        array $itemResults,
    ): SyncResult {
        $connection = $this->em->getConnection();
        $limit = $request->limit;

        // Eine Zeile mehr als noetig, um hasMore ohne zweite Abfrage zu erkennen.
        /** @var list<array{kind: string, row_id: string, version: int|string}> $rows */
        $rows = $connection->fetchAllAssociative(
            'SELECT kind, row_id, version FROM sync_view WHERE user_id = :user AND version > :since ORDER BY version LIMIT :limit',
            ['user' => $userId, 'since' => $since, 'limit' => $limit + 1],
            ['since' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER],
        );

        $hasMore = \count($rows) > $limit;
        $page = \array_slice($rows, 0, $limit);

        // Bei abgeschnittener Seite muss der Cursor exakt auf der letzten
        // ausgelieferten Zeile stehen. Sonst ist der Zaehlerstand richtig: er
        // kann ueber der hoechsten Zeilenversion liegen, wenn Zeilen entfernt
        // wurden, und erspart dem Client eine Leerabfrage.
        $serverCursor = $hasMore && [] !== $page ? (int) $page[\count($page) - 1]['version'] : $counter;

        $versions = [];
        $listIds = [];
        $itemIds = [];
        $revoked = [];
        foreach ($page as $row) {
            $versions[$row['kind'].':'.$row['row_id']] = (int) $row['version'];
            match ($row['kind']) {
                SyncView::KIND_LIST => $listIds[] = $row['row_id'],
                SyncView::KIND_ITEM => $itemIds[] = $row['row_id'],
                default => $revoked[] = $row['row_id'],
            };
        }

        // Jede gepushte Zeile bekommt ihren autoritativen Serverstand zurueck,
        // auch wenn sie ausserhalb des Pull-Fensters liegt. Ohne das behaelt ein
        // Client eine per LWW abgelehnte Aenderung dauerhaft, weil die Zeile
        // ihre alte Version <= since behaelt (CLAUDE.md 5.2, Schritt 5).
        // Nur Zeilen, die dieses Konto sehen darf.
        $echo = $this->visibleVersions($userId, [
            SyncView::KIND_LIST => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $request->pushedListIds),
            SyncView::KIND_ITEM => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $request->pushedItemIds),
        ]);
        foreach ($echo as $kindAndId => $version) {
            if (isset($versions[$kindAndId])) {
                continue;
            }
            $versions[$kindAndId] = $version;
            [$kind, $id] = explode(':', $kindAndId, 2);
            if (SyncView::KIND_LIST === $kind) {
                $listIds[] = $id;
            } else {
                $itemIds[] = $id;
            }
        }

        $outLists = array_values($this->listRepository->findByIds($listIds));
        $outItems = array_values($this->itemRepository->findByIds($itemIds));

        $listVersion = static fn (ShoppingList $l): int => $versions[SyncView::KIND_LIST.':'.$l->getId()->toRfc4122()];
        $itemVersion = static fn (ShoppingItem $i): int => $versions[SyncView::KIND_ITEM.':'.$i->getId()->toRfc4122()];
        usort($outLists, static fn (ShoppingList $a, ShoppingList $b): int => $listVersion($a) <=> $listVersion($b));
        usort($outItems, static fn (ShoppingItem $a, ShoppingItem $b): int => $itemVersion($a) <=> $itemVersion($b));

        return new SyncResult(
            $serverCursor,
            $hasMore,
            $fullResync,
            $outLists,
            $outItems,
            $listResults,
            $itemResults,
            $userId,
            $versions,
            $revoked,
            $this->access->memberCounts(array_map(static fn (ShoppingList $l): string => $l->getId()->toRfc4122(), $outLists)),
        );
    }

    /**
     * @param array<string, list<string>> $idsByKind
     *
     * @return array<string, int> "kind:id" => Version des Kontos
     */
    private function visibleVersions(string $userId, array $idsByKind): array
    {
        $versions = [];
        foreach ($idsByKind as $kind => $ids) {
            if ([] === $ids) {
                continue;
            }

            /** @var array<string, int|string> $rows */
            $rows = $this->em->getConnection()->fetchAllKeyValue(
                'SELECT row_id, version FROM sync_view WHERE user_id = :user AND kind = :kind AND row_id IN (:ids)',
                ['user' => $userId, 'kind' => $kind, 'ids' => $ids],
                ['ids' => ArrayParameterType::STRING],
            );
            foreach ($rows as $id => $version) {
                $versions[$kind.':'.$id] = (int) $version;
            }
        }

        return $versions;
    }
}
