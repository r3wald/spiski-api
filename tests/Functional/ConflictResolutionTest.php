<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Die Konfliktszenarien aus CLAUDE.md 9.
 */
final class ConflictResolutionTest extends ApiTestCase
{
    public function testLastWriteWinsByUpdatedAt(): void
    {
        $account = self::uuid();
        $deviceA = self::uuid();
        $deviceB = self::uuid();
        $listId = self::uuid();

        $this->sync($account, [
            'since' => 0,
            'clientId' => $deviceA,
            'changes' => ['lists' => [self::listRow($listId, '2026-03-01T10:00:00.000Z', 'Alt')]],
        ]);

        $response = $this->sync($account, [
            'since' => 0,
            'clientId' => $deviceB,
            'changes' => ['lists' => [self::listRow($listId, '2026-03-01T11:00:00.000Z', 'Neu')]],
        ]);

        $row = self::findRow($response, 'lists', $listId);
        self::assertNotNull($row);
        self::assertSame('Neu', $row['name']);
        self::assertTrue(self::findPushResult($response, 'lists', $listId)['accepted']);
    }

    public function testOlderWriteLosesRegardlessOfArrivalOrder(): void
    {
        $account = self::uuid();
        $listId = self::uuid();

        $this->sync($account, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [self::listRow($listId, '2026-03-01T11:00:00.000Z', 'Neu')]],
        ]);

        $response = $this->sync($account, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [self::listRow($listId, '2026-03-01T10:00:00.000Z', 'Alt')]],
        ]);

        $result = self::findPushResult($response, 'lists', $listId);
        self::assertNotNull($result);
        self::assertFalse($result['accepted']);
        self::assertSame('stale', $result['reason']);

        $row = self::findRow($response, 'lists', $listId);
        self::assertNotNull($row);
        self::assertSame('Neu', $row['name'], 'Der Serverstand bleibt unveraendert.');
    }

    /**
     * Der eigentliche Zweck von Schritt 5 in CLAUDE.md 5.2: eine abgelehnte
     * Zeile behaelt ihre alte serverVersion. Liegt die unterhalb von `since`,
     * taucht sie im normalen Pull-Fenster nicht auf – der Client haette seine
     * abweichende Fassung sonst dauerhaft behalten.
     */
    public function testRejectedPushStillReturnsServerStateOutsidePullWindow(): void
    {
        $account = self::uuid();
        $listId = self::uuid();

        // Geraet B schreibt den aktuellen Stand.
        $afterB = $this->sync($account, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [self::listRow($listId, '2026-03-01T12:00:00.000Z', 'Von B')]],
        ]);
        $cursor = $afterB['serverCursor'];

        // Geraet A kennt diesen Stand bereits (since == cursor) und pusht eine
        // aeltere Aenderung. Das Pull-Fenster serverVersion > since ist leer.
        $response = $this->sync($account, [
            'since' => $cursor,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [self::listRow($listId, '2026-03-01T09:00:00.000Z', 'Von A')]],
        ]);

        $result = self::findPushResult($response, 'lists', $listId);
        self::assertNotNull($result);
        self::assertFalse($result['accepted']);
        self::assertSame('stale', $result['reason']);

        $row = self::findRow($response, 'lists', $listId);
        self::assertNotNull($row, 'Abgelehnte Zeilen muessen mit Serverstand zurueckkommen.');
        self::assertSame('Von B', $row['name']);
        self::assertLessThanOrEqual($cursor, $row['serverVersion']);
    }

    public function testTieOnUpdatedAtIsBrokenByClientIdDeterministically(): void
    {
        $lower = '00000000-0000-4000-8000-000000000001';
        $higher = 'ffffffff-0000-4000-8000-000000000001';
        $sameTimestamp = '2026-03-01T10:00:00.000Z';

        // Reihenfolge 1: erst das kleinere, dann das groessere Geraet.
        $accountA = self::uuid();
        $listA = self::uuid();
        $this->sync($accountA, [
            'since' => 0,
            'clientId' => $lower,
            'changes' => ['lists' => [self::listRow($listA, $sameTimestamp, 'Von klein')]],
        ]);
        $first = $this->sync($accountA, [
            'since' => 0,
            'clientId' => $higher,
            'changes' => ['lists' => [self::listRow($listA, $sameTimestamp, 'Von gross')]],
        ]);

        // Reihenfolge 2: umgekehrt.
        $accountB = self::uuid();
        $listB = self::uuid();
        $this->sync($accountB, [
            'since' => 0,
            'clientId' => $higher,
            'changes' => ['lists' => [self::listRow($listB, $sameTimestamp, 'Von gross')]],
        ]);
        $second = $this->sync($accountB, [
            'since' => 0,
            'clientId' => $lower,
            'changes' => ['lists' => [self::listRow($listB, $sameTimestamp, 'Von klein')]],
        ]);

        self::assertSame('Von gross', self::findRow($first, 'lists', $listA)['name']);
        self::assertSame(
            'Von gross',
            self::findRow($second, 'lists', $listB)['name'],
            'Das Ergebnis darf nicht von der Reihenfolge abhaengen.',
        );

        self::assertFalse(self::findPushResult($second, 'lists', $listB)['accepted']);
    }

    public function testDeletionWinsWhenItIsNewer(): void
    {
        $account = self::uuid();
        $listId = self::uuid();

        $this->sync($account, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [self::listRow($listId, '2026-03-01T10:00:00.000Z', 'Aktiv')]],
        ]);

        $response = $this->sync($account, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [
                self::listRow($listId, '2026-03-01T11:00:00.000Z', 'Aktiv', '2026-03-01T11:00:00.000Z'),
            ]],
        ]);

        $row = self::findRow($response, 'lists', $listId);
        self::assertNotNull($row);
        self::assertSame('2026-03-01T11:00:00.000Z', $row['deletedAt']);
    }

    public function testUpdateWinsOverOlderDeletion(): void
    {
        $account = self::uuid();
        $listId = self::uuid();

        $this->sync($account, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [
                self::listRow($listId, '2026-03-01T10:00:00.000Z', 'Geloescht', '2026-03-01T10:00:00.000Z'),
            ]],
        ]);

        $response = $this->sync($account, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [self::listRow($listId, '2026-03-01T11:00:00.000Z', 'Wiederbelebt')]],
        ]);

        $row = self::findRow($response, 'lists', $listId);
        self::assertNotNull($row);
        self::assertNull($row['deletedAt'], 'Die spaetere Aenderung hebt den Tombstone auf.');
        self::assertSame('Wiederbelebt', $row['name']);
    }

    public function testIdenticalRepeatedPushDoesNotAdvanceServerVersion(): void
    {
        $account = self::uuid();
        $client = self::uuid();
        $listId = self::uuid();
        $row = self::listRow($listId, '2026-03-01T10:00:00.000Z');

        $first = $this->sync($account, ['since' => 0, 'clientId' => $client, 'changes' => ['lists' => [$row]]]);
        $versionAfterFirst = self::findRow($first, 'lists', $listId)['serverVersion'];

        $second = $this->sync($account, ['since' => 0, 'clientId' => $client, 'changes' => ['lists' => [$row]]]);

        self::assertTrue(self::findPushResult($second, 'lists', $listId)['accepted']);
        self::assertSame(
            $versionAfterFirst,
            self::findRow($second, 'lists', $listId)['serverVersion'],
            'Ein Retry desselben Geraets darf den Zaehler nicht weitertreiben.',
        );
        self::assertSame($first['serverCursor'], $second['serverCursor']);
    }

    public function testFutureTimestampIsClamped(): void
    {
        $account = self::uuid();
        $listId = self::uuid();

        $future = (new \DateTimeImmutable('+1 day'))->format('Y-m-d\TH:i:s.v\Z');

        $response = $this->sync($account, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [self::listRow($listId, $future)]],
        ]);

        $row = self::findRow($response, 'lists', $listId);
        self::assertNotNull($row);

        $stored = new \DateTimeImmutable($row['updatedAt']);
        $upperBound = new \DateTimeImmutable('+10 minutes');

        self::assertLessThan(
            $upperBound,
            $stored,
            'updatedAt muss auf now + Toleranz gekappt werden, sonst gewinnt eine falsch gestellte Uhr dauerhaft.',
        );
    }
}
