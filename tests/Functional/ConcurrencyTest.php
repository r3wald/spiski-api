<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Nebenlaeufigkeit des serverVersion-Zaehlers (CLAUDE.md 5.2, Schritt 1).
 */
final class ConcurrencyTest extends ApiTestCase
{
    /**
     * Prueft die Sperre, auf der die Zaehlervergabe aufsetzt: solange eine
     * Transaktion die Nutzerzeile haelt, kommt keine zweite an sie heran.
     *
     * Echte parallele HTTP-Requests laufen hier nicht – das braeuchte mehrere
     * Prozesse. Getestet ist deshalb der Mechanismus, nicht der komplette
     * Request-Durchlauf.
     */
    public function testUserRowLockBlocksConcurrentAccess(): void
    {
        $account = self::uuid();
        $this->sync($account, ['since' => 0, 'clientId' => self::uuid()]);

        $params = $this->connection()->getParams();

        $blocker = DriverManager::getConnection($params);
        $contender = DriverManager::getConnection($params);

        $blocker->beginTransaction();
        $blocker->executeQuery('SELECT version_counter FROM app_user WHERE id = ? FOR UPDATE', [$account]);

        $contender->beginTransaction();
        $contender->executeStatement("SET LOCAL lock_timeout = '300ms'");

        try {
            $contender->executeQuery('SELECT version_counter FROM app_user WHERE id = ? FOR UPDATE', [$account]);
            self::fail('Die zweite Transaktion haette blockieren muessen.');
        } catch (DbalException $e) {
            self::assertStringContainsStringIgnoringCase('lock timeout', $e->getMessage());
        } finally {
            $contender->rollBack();
            $blocker->rollBack();
            $contender->close();
            $blocker->close();
        }
    }

    public function testServerVersionsAreUniqueAcrossBothTables(): void
    {
        $account = self::uuid();
        $client = self::uuid();
        $listId = self::uuid();

        $this->sync($account, [
            'since' => 0,
            'clientId' => $client,
            'changes' => ['lists' => [self::listRow($listId, '2026-05-01T10:00:00.000Z')]],
        ]);

        for ($round = 0; $round < 5; ++$round) {
            $items = [];
            for ($i = 0; $i < 4; ++$i) {
                $items[] = self::itemRow(self::uuid(), $listId, \sprintf('2026-05-01T1%d:0%d:00.000Z', $round, $i));
            }

            $this->sync($account, ['since' => 0, 'clientId' => $client, 'changes' => ['items' => $items]]);
        }

        /** @var list<int> $versions */
        $versions = array_map(intval(...), $this->connection()->fetchFirstColumn(
            <<<'SQL'
                SELECT server_version FROM shopping_list WHERE user_id = :id
                UNION ALL
                SELECT server_version FROM shopping_item WHERE user_id = :id
                SQL,
            ['id' => $account],
        ));

        self::assertCount(21, $versions);
        self::assertSameSize($versions, array_unique($versions), 'Jede Version darf nur einmal vergeben sein.');
        self::assertSame(range(1, 21), $versions, 'Der Zaehler muss luecken- und sprungfrei hochlaufen.');
    }
}
