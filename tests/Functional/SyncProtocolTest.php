<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Sync\SyncLimits;

/**
 * Ownership, Pagination, fullResync und die Feld-Constraints.
 */
final class SyncProtocolTest extends ApiTestCase
{
    public function testItemWithUnknownListIsRejected(): void
    {
        $account = self::uuid();
        $itemId = self::uuid();

        $response = $this->sync($account, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['items' => [self::itemRow($itemId, self::uuid(), '2026-04-01T10:00:00.000Z')]],
        ]);

        $result = self::findPushResult($response, 'items', $itemId);
        self::assertNotNull($result);
        self::assertFalse($result['accepted']);
        self::assertSame('unknown_list', $result['reason']);
        self::assertSame([], $response['changes']['items']);
    }

    public function testItemCannotBeWrittenIntoForeignUsersList(): void
    {
        $victim = self::uuid();
        $foreignListId = self::uuid();

        $this->sync($victim, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [self::listRow($foreignListId, '2026-04-01T10:00:00.000Z')]],
        ]);

        $attacker = self::uuid();
        $itemId = self::uuid();

        $response = $this->sync($attacker, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['items' => [self::itemRow($itemId, $foreignListId, '2026-04-01T11:00:00.000Z')]],
        ]);

        $result = self::findPushResult($response, 'items', $itemId);
        self::assertNotNull($result);
        self::assertSame('unknown_list', $result['reason']);
        self::assertSame(
            0,
            (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM shopping_item'),
            'Es darf keine Zeile in einer fremden Liste entstehen.',
        );
    }

    public function testListIsProcessedBeforeItemInSameRequest(): void
    {
        $account = self::uuid();
        $listId = self::uuid();
        $itemId = self::uuid();

        $response = $this->sync($account, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => [
                'lists' => [self::listRow($listId, '2026-04-01T10:00:00.000Z')],
                'items' => [self::itemRow($itemId, $listId, '2026-04-01T10:00:01.000Z')],
            ],
        ]);

        self::assertTrue(self::findPushResult($response, 'items', $itemId)['accepted']);

        $list = self::findRow($response, 'lists', $listId);
        $item = self::findRow($response, 'items', $itemId);
        self::assertNotNull($list);
        self::assertNotNull($item);
        self::assertLessThan(
            $item['serverVersion'],
            $list['serverVersion'],
            'Die Liste muss die kleinere Version bekommen, sonst kann eine Seitengrenze das Item vor seiner Liste ausliefern.',
        );
    }

    public function testSinceAboveCounterTriggersFullResync(): void
    {
        $account = self::uuid();
        $listId = self::uuid();

        $this->sync($account, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [self::listRow($listId, '2026-04-01T10:00:00.000Z')]],
        ]);

        $response = $this->sync($account, ['since' => 999999, 'clientId' => self::uuid()]);

        self::assertTrue($response['fullResync']);
        self::assertCount(1, $response['changes']['lists'], 'Der Client bekommt den kompletten Bestand.');
    }

    public function testNormalSyncDoesNotSignalFullResync(): void
    {
        $account = self::uuid();
        $response = $this->sync($account, ['since' => 0, 'clientId' => self::uuid()]);

        self::assertFalse($response['fullResync']);
    }

    public function testPaginationChainDeliversEveryRowExactlyOnce(): void
    {
        $account = self::uuid();
        $client = self::uuid();
        $listId = self::uuid();

        $lists = [self::listRow($listId, '2026-04-01T10:00:00.000Z')];
        $items = [];
        for ($i = 0; $i < 24; ++$i) {
            $items[] = self::itemRow(
                self::uuid(),
                $listId,
                \sprintf('2026-04-01T10:%02d:00.000Z', $i + 1),
                'Artikel '.$i,
            );
        }

        $this->sync($account, [
            'since' => 0,
            'clientId' => $client,
            'changes' => ['lists' => $lists, 'items' => $items],
        ]);

        // Frisches Geraet zieht in Seiten zu 10 Zeilen.
        $seenLists = [];
        $seenItems = [];
        $since = 0;
        $pages = 0;

        do {
            $page = $this->sync($account, ['since' => $since, 'clientId' => self::uuid(), 'limit' => 10]);
            ++$pages;

            foreach ($page['changes']['lists'] as $row) {
                $seenLists[$row['id']] = ($seenLists[$row['id']] ?? 0) + 1;
            }
            foreach ($page['changes']['items'] as $row) {
                $seenItems[$row['id']] = ($seenItems[$row['id']] ?? 0) + 1;
            }

            self::assertLessThanOrEqual(
                10,
                \count($page['changes']['lists']) + \count($page['changes']['items']),
                'Eine Seite darf das Limit nicht ueberschreiten.',
            );

            $since = $page['serverCursor'];
            self::assertLessThan(10, $pages, 'Die hasMore-Kette terminiert nicht.');
        } while ($page['hasMore']);

        self::assertCount(1, $seenLists);
        self::assertCount(24, $seenItems);
        self::assertSame([1], array_values(array_unique($seenLists)));
        self::assertSame([1], array_values(array_unique($seenItems)), 'Keine Zeile darf doppelt kommen.');
        self::assertGreaterThan(1, $pages, 'Der Test prueft nichts, wenn alles auf eine Seite passt.');
    }

    public function testTooManyPushedRowsIsPayloadTooLarge(): void
    {
        $account = self::uuid();
        $listId = self::uuid();

        $lists = [];
        for ($i = 0; $i <= SyncLimits::MAX_PUSH_ROWS; ++$i) {
            $lists[] = self::listRow(self::uuid(), '2026-04-01T10:00:00.000Z');
        }
        unset($listId);

        $this->request($account, ['since' => 0, 'clientId' => self::uuid(), 'changes' => ['lists' => $lists]]);

        self::assertSame(413, $this->client->getResponse()->getStatusCode());
    }

    public function testInvalidRowIsRejectedWithoutBlockingTheRest(): void
    {
        $account = self::uuid();
        $goodId = self::uuid();
        $badId = self::uuid();

        $bad = self::listRow($badId, '2026-04-01T10:00:00.000Z');
        $bad['name'] = str_repeat('x', SyncLimits::MAX_LIST_NAME_LENGTH + 1);

        $response = $this->sync($account, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [
                self::listRow($goodId, '2026-04-01T10:00:00.000Z'),
                $bad,
            ]],
        ]);

        self::assertTrue(self::findPushResult($response, 'lists', $goodId)['accepted']);

        $badResult = self::findPushResult($response, 'lists', $badId);
        self::assertNotNull($badResult);
        self::assertFalse($badResult['accepted']);
        self::assertSame('invalid', $badResult['reason']);

        self::assertNotNull(self::findRow($response, 'lists', $goodId));
        self::assertNull(self::findRow($response, 'lists', $badId));
    }

    public function testMissingRowIdIsBadRequest(): void
    {
        $row = self::listRow(self::uuid(), '2026-04-01T10:00:00.000Z');
        unset($row['id']);

        $this->request(self::uuid(), [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [$row]],
        ]);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testListQuotaIsEnforced(): void
    {
        $account = self::uuid();
        $client = self::uuid();

        $lists = [];
        for ($i = 0; $i < SyncLimits::MAX_LISTS_PER_USER; ++$i) {
            $lists[] = self::listRow(self::uuid(), '2026-04-01T10:00:00.000Z');
        }
        $this->sync($account, ['since' => 0, 'clientId' => $client, 'changes' => ['lists' => $lists]]);

        $overflowId = self::uuid();
        $response = $this->sync($account, [
            'since' => 0,
            'clientId' => $client,
            'changes' => ['lists' => [self::listRow($overflowId, '2026-04-01T11:00:00.000Z')]],
        ]);

        $result = self::findPushResult($response, 'lists', $overflowId);
        self::assertNotNull($result);
        self::assertFalse($result['accepted']);
        self::assertSame('quota_exceeded', $result['reason']);
    }

    public function testMillisecondPrecisionSurvivesRoundTrip(): void
    {
        $account = self::uuid();
        $listId = self::uuid();

        $response = $this->sync($account, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [self::listRow($listId, '2026-04-01T10:00:00.482Z')]],
        ]);

        self::assertSame('2026-04-01T10:00:00.482Z', self::findRow($response, 'lists', $listId)['updatedAt']);
    }

    public function testItemCanBeMovedToAnotherList(): void
    {
        $account = self::uuid();
        $client = self::uuid();
        $listA = self::uuid();
        $listB = self::uuid();
        $itemId = self::uuid();

        $this->sync($account, [
            'since' => 0,
            'clientId' => $client,
            'changes' => [
                'lists' => [
                    self::listRow($listA, '2026-04-01T10:00:00.000Z', 'A'),
                    self::listRow($listB, '2026-04-01T10:00:00.000Z', 'B'),
                ],
                'items' => [self::itemRow($itemId, $listA, '2026-04-01T10:00:01.000Z')],
            ],
        ]);

        $moved = self::itemRow($itemId, $listB, '2026-04-01T12:00:00.000Z');
        $moved['movedAt'] = '2026-04-01T12:00:00.000Z';

        $response = $this->sync($account, [
            'since' => 0,
            'clientId' => $client,
            'changes' => ['items' => [$moved]],
        ]);

        $row = self::findRow($response, 'items', $itemId);
        self::assertNotNull($row);
        self::assertSame($listB, $row['listId']);
        self::assertSame('2026-04-01T12:00:00.000Z', $row['movedAt']);
    }
}
