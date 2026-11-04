<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Geteilte Listen (CLAUDE.md 12): Code, Beitritt, Rechte, Verteilung der
 * Aenderungen und Entzug des Zugriffs.
 */
final class SharingTest extends ApiTestCase
{
    private string $owner;
    private string $member;
    private string $listId;
    private string $itemId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = self::uuid();
        $this->member = self::uuid();
        $this->listId = self::uuid();
        $this->itemId = self::uuid();

        $this->sync($this->owner, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => [
                'lists' => [self::listRow($this->listId, '2026-06-01T10:00:00.000Z', 'Wocheneinkauf')],
                'items' => [self::itemRow($this->itemId, $this->listId, '2026-06-01T10:00:01.000Z', 'Milch')],
            ],
        ]);
        // Das Mitglied hat schon ein Konto, bevor es beitritt.
        $this->sync($this->member, ['since' => 0, 'clientId' => self::uuid()]);
    }

    public function testShareCodeIsStableAndLinkPointsToJoinPage(): void
    {
        $first = $this->share($this->owner, $this->listId);
        $second = $this->share($this->owner, $this->listId);

        self::assertSame($first['code'], $second['code']);
        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{24}$/', $first['code']);
        self::assertStringEndsWith('/join/'.$first['code'], $first['url']);
    }

    public function testMemberReceivesListAndItemsAfterJoining(): void
    {
        $listId = $this->joinWithCode($this->member, $this->share($this->owner, $this->listId)['code']);
        self::assertSame($this->listId, $listId);

        $response = $this->sync($this->member, ['since' => 0, 'clientId' => self::uuid()]);
        $list = self::findRow($response, 'lists', $this->listId);
        self::assertNotNull($list);
        self::assertSame('member', $list['role']);
        self::assertSame(1, $list['memberCount']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $list['joinedAt']);
        self::assertSame(
            (new \DateTimeImmutable((string) $this->connection()->fetchOne('SELECT joined_at FROM list_member')))
                ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            $list['joinedAt'],
        );
        self::assertSame('Milch', self::findRow($response, 'items', $this->itemId)['name'] ?? null);

        $ownerView = $this->sync($this->owner, ['since' => 0, 'clientId' => self::uuid()]);
        self::assertSame('owner', self::findRow($ownerView, 'lists', $this->listId)['role'] ?? null);
        self::assertSame(1, self::findRow($ownerView, 'lists', $this->listId)['memberCount'] ?? null);
        // Eigene Liste: kein Beitritt, das Feld ist aber immer vorhanden.
        $ownerList = self::findRow($ownerView, 'lists', $this->listId);
        self::assertArrayHasKey('joinedAt', $ownerList);
        self::assertNull($ownerList['joinedAt']);
    }

    public function testItemChangesReachEveryParticipant(): void
    {
        $this->joinWithCode($this->member, $this->share($this->owner, $this->listId)['code']);
        $ownerCursor = $this->cursor($this->owner);
        $memberCursor = $this->cursor($this->member);

        $bread = self::uuid();
        $pushed = $this->sync($this->member, [
            'since' => $memberCursor,
            'clientId' => self::uuid(),
            'changes' => ['items' => [self::itemRow($bread, $this->listId, '2026-06-02T10:00:00.000Z', 'Brot')]],
        ]);
        self::assertTrue(self::findPushResult($pushed, 'items', $bread)['accepted'] ?? false);

        $ownerView = $this->sync($this->owner, ['since' => $ownerCursor, 'clientId' => self::uuid()]);
        self::assertSame('Brot', self::findRow($ownerView, 'items', $bread)['name'] ?? null);

        // Und zurueck: der Besitzer hakt ab, das Mitglied sieht es.
        $checked = self::itemRow($bread, $this->listId, '2026-06-02T11:00:00.000Z', 'Brot');
        $checked['isChecked'] = true;
        $this->sync($this->owner, ['since' => $ownerView['serverCursor'], 'clientId' => self::uuid(), 'changes' => ['items' => [$checked]]]);

        $memberView = $this->sync($this->member, ['since' => $pushed['serverCursor'], 'clientId' => self::uuid()]);
        self::assertTrue(self::findRow($memberView, 'items', $bread)['isChecked'] ?? false);

        // Der Artikel zaehlt zum Kontingent des Besitzers.
        self::assertSame($this->owner, $this->connection()->fetchOne('SELECT user_id FROM shopping_item WHERE id = ?', [$bread]));
    }

    public function testChangedByOtherMarksChangesOfOtherAccountsOnly(): void
    {
        $this->joinWithCode($this->member, $this->share($this->owner, $this->listId)['code']);
        $ownerCursor = $this->cursor($this->owner);
        $memberCursor = $this->cursor($this->member);

        // Das Mitglied aendert die Milch: fuer den Besitzer fremd, fuer das
        // Mitglied selbst nicht – auch nicht auf einem zweiten Geraet.
        $this->sync($this->member, [
            'since' => $memberCursor,
            'clientId' => self::uuid(),
            'changes' => ['items' => [self::itemRow($this->itemId, $this->listId, '2026-06-02T10:00:00.000Z', 'Milch Ersatz')]],
        ]);

        $ownerView = $this->sync($this->owner, ['since' => $ownerCursor, 'clientId' => self::uuid()]);
        self::assertTrue(self::findRow($ownerView, 'items', $this->itemId)['changedByOther'] ?? null);

        $memberView = $this->sync($this->member, ['since' => $memberCursor, 'clientId' => self::uuid()]);
        self::assertFalse(self::findRow($memberView, 'items', $this->itemId)['changedByOther'] ?? null);

        // Die Konto-ID des Schreibers verlaesst den Server nie.
        self::assertStringNotContainsString($this->member, json_encode($ownerView, \JSON_THROW_ON_ERROR));
    }

    public function testOnlyOwnerMayChangeTheListItself(): void
    {
        $this->joinWithCode($this->member, $this->share($this->owner, $this->listId)['code']);

        $response = $this->sync($this->member, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [self::listRow($this->listId, '2026-06-03T10:00:00.000Z', 'Umbenannt')]],
        ]);

        self::assertSame('forbidden', self::findPushResult($response, 'lists', $this->listId)['reason'] ?? null);
        // Das Echo liefert den Serverstand, damit der Client seine Aenderung verwirft.
        self::assertSame('Wocheneinkauf', self::findRow($response, 'lists', $this->listId)['name'] ?? null);

        $this->shareRequest('POST', '/v1/lists/'.$this->listId.'/share', $this->member);
        self::assertResponseStatusCodeSame(403);
    }

    public function testStrangerSeesNothingAndCannotWrite(): void
    {
        $this->share($this->owner, $this->listId);
        $stranger = self::uuid();

        $response = $this->sync($stranger, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['items' => [self::itemRow(self::uuid(), $this->listId, '2026-06-03T10:00:00.000Z')]],
        ]);
        self::assertSame('unknown_list', $response['pushResults']['items'][0]['reason']);
        self::assertSame([], $response['changes']['lists']);

        $this->shareRequest('POST', '/v1/lists/'.$this->listId.'/share', $stranger);
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownCodeIsRejectedAndCodeInputIsTolerant(): void
    {
        $this->shareRequest('POST', '/v1/shares/ZZZZZZZZZZZZZZZZZZZZZZZZ', $this->member);
        self::assertResponseStatusCodeSame(404);

        $code = $this->share($this->owner, $this->listId)['code'];
        $typed = strtolower(implode('-', str_split($code, 4)));
        self::assertSame($this->listId, $this->joinWithCode($this->member, $typed));
    }

    public function testJoinIsIdempotent(): void
    {
        $code = $this->share($this->owner, $this->listId)['code'];
        $this->joinWithCode($this->member, $code);
        $this->joinWithCode($this->member, $code);
        self::assertSame($this->listId, $this->joinWithCode($this->owner, $code));

        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM list_member'));
    }

    public function testLeavingRevokesAccess(): void
    {
        $this->joinWithCode($this->member, $this->share($this->owner, $this->listId)['code']);
        $cursor = $this->cursor($this->member);

        $this->shareRequest('DELETE', '/v1/lists/'.$this->listId.'/membership', $this->member);
        self::assertResponseStatusCodeSame(204);

        $response = $this->sync($this->member, [
            'since' => $cursor,
            'clientId' => self::uuid(),
            'changes' => ['items' => [self::itemRow(self::uuid(), $this->listId, '2026-06-04T10:00:00.000Z')]],
        ]);
        self::assertSame([$this->listId], $response['revokedLists']);
        self::assertSame('unknown_list', $response['pushResults']['items'][0]['reason']);

        // Ein frischer Client sieht die Liste gar nicht mehr.
        $fresh = $this->sync($this->member, ['since' => 0, 'clientId' => self::uuid()]);
        self::assertNull(self::findRow($fresh, 'lists', $this->listId));

        $ownerView = $this->sync($this->owner, ['since' => 0, 'clientId' => self::uuid()]);
        self::assertSame(0, self::findRow($ownerView, 'lists', $this->listId)['memberCount'] ?? null);

        $this->shareRequest('DELETE', '/v1/lists/'.$this->listId.'/membership', $this->owner);
        self::assertResponseStatusCodeSame(409);
    }

    public function testRejoiningAfterLeavingDeliversTheListAgain(): void
    {
        $code = $this->share($this->owner, $this->listId)['code'];
        $this->joinWithCode($this->member, $code);
        $this->shareRequest('DELETE', '/v1/lists/'.$this->listId.'/membership', $this->member);
        $cursor = $this->cursor($this->member);

        $this->joinWithCode($this->member, $code);
        $response = $this->sync($this->member, ['since' => $cursor, 'clientId' => self::uuid()]);

        self::assertSame([], $response['revokedLists']);
        self::assertNotNull(self::findRow($response, 'lists', $this->listId));
        self::assertNotNull(self::findRow($response, 'items', $this->itemId));
    }

    public function testStoppingRevokesAllMembersAndInvalidatesCode(): void
    {
        $code = $this->share($this->owner, $this->listId)['code'];
        $second = self::uuid();
        $this->joinWithCode($this->member, $code);
        $this->joinWithCode($second, $code);
        $cursor = $this->cursor($second);

        $this->shareRequest('DELETE', '/v1/lists/'.$this->listId.'/share', $this->owner);
        self::assertResponseStatusCodeSame(204);

        self::assertSame([$this->listId], $this->sync($second, ['since' => $cursor, 'clientId' => self::uuid()])['revokedLists']);
        $this->shareRequest('POST', '/v1/shares/'.$code, self::uuid());
        self::assertResponseStatusCodeSame(404);
        self::assertNotNull(self::findRow($this->sync($this->owner, ['since' => 0, 'clientId' => self::uuid()]), 'lists', $this->listId));
    }

    public function testRenewInvalidatesOldCodeButKeepsMembers(): void
    {
        $old = $this->share($this->owner, $this->listId)['code'];
        $this->joinWithCode($this->member, $old);

        $this->shareRequest('POST', '/v1/lists/'.$this->listId.'/share/renew', $this->owner);
        self::assertResponseIsSuccessful();
        $new = $this->json()['code'];
        self::assertNotSame($old, $new);

        $this->shareRequest('POST', '/v1/shares/'.$old, self::uuid());
        self::assertResponseStatusCodeSame(404);
        self::assertSame($this->listId, $this->joinWithCode(self::uuid(), $new));
        self::assertSame(2, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM list_member'));
    }

    public function testDeletingOwnerAccountRevokesMembers(): void
    {
        $this->joinWithCode($this->member, $this->share($this->owner, $this->listId)['code']);
        $cursor = $this->cursor($this->member);

        $this->shareRequest('DELETE', '/v1/me', $this->owner);
        self::assertResponseStatusCodeSame(204);

        $response = $this->sync($this->member, ['since' => $cursor, 'clientId' => self::uuid()]);
        self::assertSame([$this->listId], $response['revokedLists']);
    }

    public function testDeletingMemberAccountLeavesTheList(): void
    {
        $this->joinWithCode($this->member, $this->share($this->owner, $this->listId)['code']);

        $this->shareRequest('DELETE', '/v1/me', $this->member);
        self::assertResponseStatusCodeSame(204);

        $ownerView = $this->sync($this->owner, ['since' => 0, 'clientId' => self::uuid()]);
        self::assertSame(0, self::findRow($ownerView, 'lists', $this->listId)['memberCount'] ?? null);
        self::assertNotNull(self::findRow($ownerView, 'items', $this->itemId));
    }

    public function testItemCannotMoveIntoListWithOtherParticipants(): void
    {
        $this->joinWithCode($this->member, $this->share($this->owner, $this->listId)['code']);

        $private = self::uuid();
        $this->sync($this->owner, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [self::listRow($private, '2026-06-05T10:00:00.000Z', 'Privat')]],
        ]);

        $response = $this->sync($this->owner, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['items' => [self::itemRow($this->itemId, $private, '2026-06-05T11:00:00.000Z', 'Milch')]],
        ]);
        self::assertSame('invalid', self::findPushResult($response, 'items', $this->itemId)['reason'] ?? null);
    }

    public function testCountersStayDenseForEveryParticipant(): void
    {
        $this->joinWithCode($this->member, $this->share($this->owner, $this->listId)['code']);

        for ($i = 0; $i < 3; ++$i) {
            $this->sync($this->member, [
                'since' => 0,
                'clientId' => self::uuid(),
                'changes' => ['items' => [self::itemRow(self::uuid(), $this->listId, \sprintf('2026-06-06T10:00:0%d.000Z', $i))]],
            ]);
        }

        foreach ([$this->owner, $this->member] as $account) {
            /** @var list<int> $versions */
            $versions = array_map(intval(...), $this->connection()->fetchFirstColumn(
                'SELECT version FROM sync_view WHERE user_id = ? ORDER BY version',
                [$account],
            ));
            $counter = (int) $this->connection()->fetchOne('SELECT version_counter FROM app_user WHERE id = ?', [$account]);

            self::assertSameSize($versions, array_unique($versions));
            self::assertSame($counter, max($versions), 'Der Zaehler steht auf der hoechsten vergebenen Version.');
        }
    }

    public function testBackfillAfterJoiningIsPaginated(): void
    {
        $items = [];
        for ($i = 0; $i < 7; ++$i) {
            $items[] = self::itemRow(self::uuid(), $this->listId, \sprintf('2026-06-07T10:00:0%d.000Z', $i));
        }
        $this->sync($this->owner, ['since' => 0, 'clientId' => self::uuid(), 'changes' => ['items' => $items]]);
        $this->joinWithCode($this->member, $this->share($this->owner, $this->listId)['code']);

        $since = 0;
        $seenItems = [];
        $seenList = false;
        for ($guard = 0; $guard < 20; ++$guard) {
            $page = $this->sync($this->member, ['since' => $since, 'clientId' => self::uuid(), 'limit' => 3]);
            // Der Client wendet je Seite erst die Listen, dann die Artikel an.
            $seenList = $seenList || null !== self::findRow($page, 'lists', $this->listId);
            foreach ($page['changes']['items'] as $row) {
                self::assertTrue($seenList, 'Kein Artikel vor seiner Liste.');
                $seenItems[$row['id']] = true;
            }
            $since = $page['serverCursor'];
            if (!$page['hasMore']) {
                break;
            }
        }

        self::assertCount(8, $seenItems);
    }

    /**
     * @return array{code: string, url: string}
     */
    private function share(string $account, string $listId): array
    {
        $this->shareRequest('POST', '/v1/lists/'.$listId.'/share', $account);
        self::assertResponseIsSuccessful();

        /** @var array{code: string, url: string} */
        return $this->json();
    }

    private function joinWithCode(string $account, string $code): string
    {
        $this->shareRequest('POST', '/v1/shares/'.rawurlencode($code), $account);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $this->json()['listId'];
    }

    private function cursor(string $account): int
    {
        return (int) $this->sync($account, ['since' => 0, 'clientId' => self::uuid()])['serverCursor'];
    }

    private function shareRequest(string $method, string $path, string $account): void
    {
        $this->client->request($method, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$account]);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
