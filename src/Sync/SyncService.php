<?php

declare(strict_types=1);

namespace App\Sync;

use App\Entity\ShoppingItem;
use App\Entity\ShoppingList;
use App\Entity\User;
use App\Repository\ShoppingItemRepository;
use App\Repository\ShoppingListRepository;
use App\Sync\Dto\ItemChange;
use App\Sync\Dto\ListChange;
use App\Sync\Dto\PushResult;
use App\Sync\Dto\RejectReason;
use App\Sync\Dto\SyncRequest;
use App\Sync\Dto\SyncResult;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Push und Pull in einer einzigen Transaktion (CLAUDE.md 5.2).
 */
final class SyncService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShoppingListRepository $listRepository,
        private readonly ShoppingItemRepository $itemRepository,
        private readonly ConflictResolver $conflictResolver,
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
        // Zeilensperre und frischer Zaehlerstand in einem Zug. em->lock() wuerde
        // zwar SELECT ... FOR UPDATE absetzen, die bereits geladene Entity aber
        // nicht neu einlesen – der Zaehler waere dann moeglicherweise veraltet.
        $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);

        $counter = $user->getVersionCounter();

        // Cursor aus einer geloeschten oder wiederhergestellten Datenbank: der
        // Client kennt eine Version, die es serverseitig nicht (mehr) gibt.
        $fullResync = $request->since > $counter;
        $since = $fullResync ? 0 : $request->since;

        $listResults = $request->invalidLists;
        $itemResults = $request->invalidItems;

        $counter = $this->pushLists($user, $request, $counter, $listResults, $ownedLists);
        $counter = $this->pushItems($user, $request, $counter, $itemResults, $ownedLists);

        $user->setVersionCounter($counter);
        $user->setLastSeenAt($now);

        // Muss vor dem Pull passieren, sonst sieht die Abfrage die soeben
        // vergebenen serverVersion-Werte nicht.
        $this->em->flush();

        return $this->pull($user, $request, $since, $counter, $fullResync, $listResults, $itemResults);
    }

    /**
     * @param list<PushResult>                $listResults
     * @param array<string, ShoppingList>|null $ownedLists
     *
     * @param-out array<string, ShoppingList> $ownedLists
     */
    private function pushLists(User $user, SyncRequest $request, int $counter, array &$listResults, ?array &$ownedLists): int
    {
        $ids = array_map(static fn (ListChange $change): Uuid => $change->id, $request->lists);
        $ownedLists = $this->listRepository->findOwnedByIds($user, $ids);
        $listCount = $this->listRepository->countForUser($user);

        foreach ($request->lists as $change) {
            $key = $change->id->toRfc4122();
            $existing = $ownedLists[$key] ?? null;

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
                if ($listCount >= SyncLimits::MAX_LISTS_PER_USER) {
                    $listResults[] = PushResult::rejected($change->id, RejectReason::QuotaExceeded);
                    continue;
                }

                $existing = new ShoppingList($change->id, $user);
                $this->em->persist($existing);
                $ownedLists[$key] = $existing;
                ++$listCount;
            }

            \assert($existing instanceof ShoppingList);
            $existing->apply($change, ++$counter, $request->clientId);
            $listResults[] = PushResult::accepted($change->id);
        }

        return $counter;
    }

    /**
     * @param list<PushResult>            $itemResults
     * @param array<string, ShoppingList> $ownedLists
     */
    private function pushItems(User $user, SyncRequest $request, int $counter, array &$itemResults, array $ownedLists): int
    {
        $ids = array_map(static fn (ItemChange $change): Uuid => $change->id, $request->items);
        $ownedItems = $this->itemRepository->findOwnedByIds($user, $ids);
        $itemCount = $this->itemRepository->countForUser($user);

        // Listen, die noch nicht geladen sind, weil sie in diesem Request nicht
        // mitgepusht wurden. Ein einziger Nachschlag statt N Einzelabfragen.
        $missing = [];
        foreach ($request->items as $change) {
            $key = $change->listId->toRfc4122();
            if (!isset($ownedLists[$key])) {
                $missing[$key] = $change->listId;
            }
        }
        foreach ($this->listRepository->findOwnedByIds($user, array_values($missing)) as $key => $list) {
            $ownedLists[$key] = $list;
        }

        foreach ($request->items as $change) {
            // Die Aufloesung ist auf $user eingeschraenkt: eine fremde listId
            // findet nichts und wird abgelehnt. Damit ist das Schreiben in
            // fremde Listen strukturell ausgeschlossen (CLAUDE.md 5.2).
            $list = $ownedLists[$change->listId->toRfc4122()] ?? null;
            if (null === $list) {
                $itemResults[] = PushResult::rejected($change->id, RejectReason::UnknownList);
                continue;
            }

            $key = $change->id->toRfc4122();
            $existing = $ownedItems[$key] ?? null;

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
                if ($itemCount >= SyncLimits::MAX_ITEMS_PER_USER) {
                    $itemResults[] = PushResult::rejected($change->id, RejectReason::QuotaExceeded);
                    continue;
                }

                $existing = new ShoppingItem($change->id, $user, $list);
                $this->em->persist($existing);
                $ownedItems[$key] = $existing;
                ++$itemCount;
            }

            \assert($existing instanceof ShoppingItem);
            $existing->apply($change, $list, ++$counter, $request->clientId);
            $itemResults[] = PushResult::accepted($change->id);
        }

        return $counter;
    }

    /**
     * @param list<PushResult> $listResults
     * @param list<PushResult> $itemResults
     */
    private function pull(
        User $user,
        SyncRequest $request,
        int $since,
        int $counter,
        bool $fullResync,
        array $listResults,
        array $itemResults,
    ): SyncResult {
        $limit = $request->limit;

        // Eine Zeile mehr als noetig, um hasMore ohne zweite Abfrage zu erkennen.
        $pageLists = $this->listRepository->findChangedSince($user, $since, $limit + 1);
        $pageItems = $this->itemRepository->findChangedSince($user, $since, $limit + 1);

        $outLists = [];
        $outItems = [];
        $listIndex = 0;
        $itemIndex = 0;
        $delivered = 0;
        $lastVersion = null;

        // Beide Tabellen teilen sich denselben Zaehler, die serverVersion-Werte
        // sind also ueber beide hinweg eindeutig und total geordnet.
        while ($delivered < $limit) {
            $list = $pageLists[$listIndex] ?? null;
            $item = $pageItems[$itemIndex] ?? null;

            if (null === $list && null === $item) {
                break;
            }

            if (null !== $list && (null === $item || $list->getServerVersion() <= $item->getServerVersion())) {
                $outLists[] = $list;
                $lastVersion = $list->getServerVersion();
                ++$listIndex;
            } else {
                $outItems[] = $item;
                $lastVersion = $item->getServerVersion();
                ++$itemIndex;
            }

            ++$delivered;
        }

        $hasMore = isset($pageLists[$listIndex]) || isset($pageItems[$itemIndex]);

        // Bei abgeschnittener Seite muss der Cursor exakt auf der letzten
        // ausgelieferten Zeile stehen. Sonst ist der Zaehlerstand richtig: er
        // kann ueber der hoechsten Zeilenversion liegen, wenn Zeilen entfernt
        // wurden, und erspart dem Client eine Leerabfrage.
        $serverCursor = $hasMore ? ($lastVersion ?? $since) : $counter;

        // Jede gepushte Zeile bekommt ihren autoritativen Serverstand zurueck,
        // auch wenn sie ausserhalb des Pull-Fensters liegt. Ohne das behaelt ein
        // Client eine per LWW abgelehnte Aenderung dauerhaft, weil die Zeile
        // ihre alte serverVersion <= since behaelt (CLAUDE.md 5.2, Schritt 5).
        $outLists = $this->mergeEcho(
            $outLists,
            $this->listRepository->findOwnedByIds($user, $request->pushedListIds),
        );
        $outItems = $this->mergeEcho(
            $outItems,
            $this->itemRepository->findOwnedByIds($user, $request->pushedItemIds),
        );

        usort($outLists, static fn (ShoppingList $a, ShoppingList $b): int => $a->getServerVersion() <=> $b->getServerVersion());
        usort($outItems, static fn (ShoppingItem $a, ShoppingItem $b): int => $a->getServerVersion() <=> $b->getServerVersion());

        return new SyncResult($serverCursor, $hasMore, $fullResync, $outLists, $outItems, $listResults, $itemResults);
    }

    /**
     * @template T of ShoppingList|ShoppingItem
     *
     * @param list<T>            $page
     * @param array<string, T>   $echo
     *
     * @return list<T>
     */
    private function mergeEcho(array $page, array $echo): array
    {
        $seen = [];
        foreach ($page as $row) {
            $seen[$row->getId()->toRfc4122()] = true;
        }

        foreach ($echo as $key => $row) {
            if (!isset($seen[$key])) {
                $page[] = $row;
            }
        }

        return $page;
    }
}
