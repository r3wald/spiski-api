<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;

final class AdminTest extends ApiTestCase
{
    /** Siehe ADMIN_TOKEN in .env.test. */
    private const ADMIN_TOKEN = 'test-admin-token-0123456789abcdef0123456789';

    public function testAdminEndpointWithoutTokenIsUnauthorized(): void
    {
        $this->client->request('GET', '/v1/admin/lists');

        $response = $this->client->getResponse();
        self::assertSame(401, $response->getStatusCode());
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
    }

    public function testWrongTokenIsUnauthorized(): void
    {
        $this->adminRequest('/v1/admin/lists', 'Bearer ' . self::ADMIN_TOKEN . 'x');

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testNonBearerSchemeIsUnauthorized(): void
    {
        $this->adminRequest('/v1/admin/lists', 'Basic ' . self::ADMIN_TOKEN);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testAccountUuidIsNotAnAdminToken(): void
    {
        // Ein gueltiger Nutzer-Token darf die Admin-Daten nicht oeffnen.
        $account = self::uuid();
        $this->sync($account, ['since' => 0, 'clientId' => self::uuid()]);

        $this->adminRequest('/v1/admin/lists', 'Bearer ' . $account);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminTokenIsNotAnAccountToken(): void
    {
        // Umgekehrt: das Admin-Token ist keine UUID und legt keinen Account an.
        $this->client->request(
            'POST',
            '/v1/sync',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . self::ADMIN_TOKEN],
            content: '{"since":0,"clientId":"' . self::uuid() . '"}',
        );

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testListsAllListsOfAllAccountsNewestFirst(): void
    {
        [$accountA, $accountB] = $this->seed();

        $body = $this->getLists();

        self::assertSame(3, $body['total']);
        self::assertSame(2, $body['ownerCount']);

        /** @var list<array<string, mixed>> $lists */
        $lists = $body['lists'];
        self::assertSame(['Drogerie', 'Wocheneinkauf', 'Altlast'], array_column($lists, 'name'));

        $drogerie = $lists[0];
        $einkauf = $lists[1];
        $altlast = $lists[2];

        self::assertSame(0, $drogerie['itemCount']);
        self::assertSame(2, $einkauf['itemCount']);
        self::assertSame(1, $einkauf['activeItemCount'], 'Gelöschte Artikel zaehlen nicht als aktiv.');
        self::assertNull($einkauf['deletedAt']);
        self::assertSame('2026-01-15T10:00:00.000Z', $altlast['deletedAt']);
        self::assertSame('2026-03-01T10:00:00.000Z', $drogerie['updatedAt']);
        self::assertSame(4283215696, $einkauf['color']);

        // Dasselbe Kuerzel fuer denselben Account, ein anderes fuer den zweiten.
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', (string) $einkauf['owner']);
        self::assertSame($einkauf['owner'], $altlast['owner']);
        self::assertNotSame($einkauf['owner'], $drogerie['owner']);

        // Die Account-UUID ist das Zugangs-Secret der Nutzer und darf nie ausgeliefert werden.
        $raw = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString($accountA, $raw);
        self::assertStringNotContainsString($accountB, $raw);
        self::assertStringNotContainsString(
            hash('sha256', $accountA),
            $raw,
            'Nur das gekuerzte Kuerzel, nicht der volle Hash.',
        );
    }

    public function testResponseIsNotCacheable(): void
    {
        $this->seed();
        $this->getLists();

        $response = $this->client->getResponse();
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
    }

    public function testCanExcludeDeletedLists(): void
    {
        $this->seed();

        $body = $this->getLists('includeDeleted=0');

        self::assertSame(2, $body['total']);
        /** @var list<array<string, mixed>> $lists */
        $lists = $body['lists'];
        self::assertSame(['Drogerie', 'Wocheneinkauf'], array_column($lists, 'name'));
    }

    public function testPagination(): void
    {
        $this->seed();

        $body = $this->getLists('limit=1&offset=1');

        self::assertSame(3, $body['total'], 'total ist unabhaengig vom Seitenfenster.');
        self::assertSame(1, $body['limit']);
        self::assertSame(1, $body['offset']);
        /** @var list<array<string, mixed>> $lists */
        $lists = $body['lists'];
        self::assertSame(['Wocheneinkauf'], array_column($lists, 'name'));
    }

    public function testEmptyDatabaseReturnsEmptyList(): void
    {
        $body = $this->getLists();

        self::assertSame(0, $body['total']);
        self::assertSame(0, $body['ownerCount']);
        self::assertSame([], $body['lists']);
    }

    #[DataProvider('invalidParameters')]
    public function testInvalidParametersAreUnprocessable(string $query): void
    {
        $this->adminRequest('/v1/admin/lists?' . $query, 'Bearer ' . self::ADMIN_TOKEN);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidParameters(): iterable
    {
        yield 'limit 0' => ['limit=0'];
        yield 'limit zu gross' => ['limit=501'];
        yield 'limit kein Integer' => ['limit=abc'];
        yield 'offset negativ' => ['offset=-1'];
        yield 'q zu lang' => ['q=' . str_repeat('a', 101)];
        yield 'status unbekannt' => ['status=archived'];
        yield 'owner kein Hex' => ['owner=xyz'];
        yield 'owner zu lang' => ['owner=' . str_repeat('a', 13)];
        yield 'encryption unbekannt' => ['encryption=foo'];
        yield 'sort unbekannt' => ['sort=user_id'];
        yield 'sort als SQL' => ['sort=' . rawurlencode('l.id; DROP TABLE app_user')];
        yield 'dir unbekannt' => ['dir=up'];
        yield 'ageField unbekannt' => ['ageField=deleted'];
        yield 'minAgeDays negativ' => ['minAgeDays=-1'];
        yield 'maxAgeDays kein Integer' => ['maxAgeDays=7d'];
    }

    public function testArrayQueryParameterIsABadRequest(): void
    {
        $this->adminRequest('/v1/admin/lists?q[]=x', 'Bearer ' . self::ADMIN_TOKEN);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testFiltersByStatus(): void
    {
        $this->seed();

        self::assertSame(['Drogerie', 'Wocheneinkauf'], $this->names('status=active'));
        self::assertSame(['Altlast'], $this->names('status=deleted'));
        self::assertSame(['Drogerie', 'Wocheneinkauf', 'Altlast'], $this->names('status=all'));
        self::assertSame(['Altlast'], $this->names('status=deleted&includeDeleted=0'), 'status hat Vorrang.');
    }

    public function testFiltersByOwner(): void
    {
        $this->seed();
        $lists = $this->listsByName($this->getLists());
        $ownerA = (string) $lists['Wocheneinkauf']['owner'];

        $body = $this->getLists('owner=' . strtoupper(substr($ownerA, 0, 4)));
        self::assertSame(1, $body['ownerCount'], 'Prefix, ohne Gross-/Kleinschreibung');
        self::assertSame(['Wocheneinkauf', 'Altlast'], $this->names('owner=' . $ownerA));
        self::assertSame(['Wocheneinkauf'], $this->names('owner=' . $ownerA . '&status=active'));
    }

    public function testOwnersListsEveryAccountWithListsOnce(): void
    {
        [$accountA, $accountB] = $this->seed();
        $this->sync(self::uuid(), ['since' => 0, 'clientId' => self::uuid()]); // Konto ohne Listen

        $this->client->request('GET', '/v1/admin/owners');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $owners = $this->getJson('/v1/admin/owners')['owners'];
        self::assertIsArray($owners);
        self::assertCount(2, $owners, 'Nur Konten mit Listen, jedes einmal.');

        $lists = $this->listsByName($this->getLists());
        $byOwner = array_column($owners, null, 'owner');
        self::assertSame(
            ['owner' => $lists['Wocheneinkauf']['owner'], 'encryptionState' => 'plaintext', 'listCount' => 2, 'activeListCount' => 1],
            $byOwner[$lists['Wocheneinkauf']['owner']],
            'Geloeschte Listen zaehlen mit, aber nicht als aktiv.',
        );
        self::assertSame(1, $byOwner[$lists['Drogerie']['owner']]['listCount']);

        $refs = array_column($owners, 'owner');
        $sorted = $refs;
        sort($sorted);
        self::assertSame($sorted, $refs, 'nach Kuerzel sortiert');

        $raw = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString($accountA, $raw);
        self::assertStringNotContainsString($accountB, $raw);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testFiltersByEncryptionStateOfTheOwner(): void
    {
        $this->seed();
        $account = self::uuid();
        $this->client->request(
            'POST',
            '/v1/me/encryption/commit',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $account,
                'HTTP_X_SPISKI_PROTOCOL' => '2',
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode(['clientId' => self::uuid(), 'keyCheck' => self::cipher()], \JSON_THROW_ON_ERROR),
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $list = self::listRow(self::uuid(), '2026-06-01T10:00:00.000Z');
        unset($list['name']);
        $this->client->request(
            'POST',
            '/v1/sync',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $account,
                'HTTP_X_SPISKI_PROTOCOL' => '2',
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode(
                [
                    'since' => 0,
                    'clientId' => self::uuid(),
                    'changes' => ['lists' => [$list + ['enc' => self::cipher(), 'wrappedKey' => self::cipher()]]],
                ],
                \JSON_THROW_ON_ERROR,
            ),
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $encrypted = $this->getLists('encryption=encrypted');
        self::assertSame(1, $encrypted['total']);
        self::assertSame('encrypted', $encrypted['lists'][0]['encryptionState']);
        self::assertNull($encrypted['lists'][0]['name']);
        self::assertTrue($encrypted['lists'][0]['encrypted']);

        self::assertSame(3, $this->getLists('encryption=plaintext')['total']);
        self::assertSame(0, $this->getLists('encryption=migrating')['total']);
        self::assertSame(4, $this->getLists('encryption=')['total'], 'leer = alle');
    }

    public function testFiltersByAge(): void
    {
        $account = self::uuid();
        $daysAgo = static fn(int $days): string => new \DateTimeImmutable(
            \sprintf('-%d days', $days),
            new \DateTimeZone('UTC'),
        )->format('Y-m-d\TH:i:s.v\Z');

        $this->sync($account, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [
                array_merge(self::listRow(self::uuid(), $daysAgo(0), 'Neu'), ['createdAt' => $daysAgo(2)]),
                array_merge(self::listRow(self::uuid(), $daysAgo(1), 'Mittel'), ['createdAt' => $daysAgo(40)]),
                array_merge(self::listRow(self::uuid(), $daysAgo(200), 'Alt'), ['createdAt' => $daysAgo(400)]),
            ]],
        ]);

        self::assertSame(['Neu'], $this->names('maxAgeDays=7'));
        self::assertSame(['Mittel', 'Alt'], $this->names('minAgeDays=30'));
        self::assertSame(['Mittel'], $this->names('minAgeDays=30&maxAgeDays=365'));
        self::assertSame(['Alt'], $this->names('minAgeDays=365'));
        self::assertSame([], $this->names('minAgeDays=500'));
        // Nach letzter Aenderung statt nach Anlage: nur "Alt" wurde lange nicht geaendert.
        self::assertSame(['Alt'], $this->names('ageField=updated&minAgeDays=30'));
        self::assertSame(['Neu', 'Mittel'], $this->names('ageField=updated&maxAgeDays=7&sort=createdAt'));
        self::assertSame(['Neu', 'Mittel', 'Alt'], $this->names('minAgeDays=&maxAgeDays='), 'leer = kein Filter');
    }

    public function testSortsByTheRequestedColumn(): void
    {
        $this->seed();
        $this->sync(self::uuid(), [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [array_merge(self::listRow(self::uuid(), '2026-01-20T10:00:00.000Z', 'apfel'), [
                'createdAt' => '2025-12-01T00:00:00.000Z',
            ])]],
        ]);

        self::assertSame(
            ['Drogerie', 'Wocheneinkauf', 'apfel', 'Altlast'],
            $this->names(''),
            'Standard: zuletzt geaendert zuerst',
        );
        self::assertSame(['Altlast', 'apfel', 'Wocheneinkauf', 'Drogerie'], $this->names('sort=updatedAt&dir=asc'));
        self::assertSame(
            ['Altlast', 'apfel', 'Drogerie', 'Wocheneinkauf'],
            $this->names('sort=name&dir=asc'),
            'ohne Gross-/Kleinschreibung',
        );
        self::assertSame(['Wocheneinkauf', 'Drogerie', 'apfel', 'Altlast'], $this->names('sort=name&dir=desc'));
        self::assertSame('apfel', $this->names('sort=createdAt&dir=asc')[0]);
        self::assertSame('Wocheneinkauf', $this->names('sort=items&dir=desc')[0], 'nach aktiven Eintraegen');

        $byOwner = $this->getLists('sort=owner&dir=asc')['lists'];
        $owners = array_column($byOwner, 'owner');
        $sorted = $owners;
        sort($sorted);
        self::assertSame($sorted, $owners);
    }

    public function testSortsByNumberOfUsers(): void
    {
        $this->seed();
        $this->share(
            $this->accountOfList('Wocheneinkauf'),
            $this->listId('Wocheneinkauf'),
            [self::uuid(), self::uuid()],
        );
        $this->share($this->accountOfList('Drogerie'), $this->listId('Drogerie'), [self::uuid()]);

        self::assertSame(['Wocheneinkauf', 'Drogerie', 'Altlast'], $this->names('sort=users&dir=desc'));
        self::assertSame(['Altlast', 'Drogerie', 'Wocheneinkauf'], $this->names('sort=users&dir=asc'));
    }

    public function testDetailContainsTheListAndAllItems(): void
    {
        [$accountA] = $this->seed();
        $listId = $this->listId('Wocheneinkauf');

        $detail = $this->getJson('/v1/admin/lists/' . strtoupper($listId));

        self::assertSame($listId, $detail['id']);
        self::assertSame('Wocheneinkauf', $detail['name']);
        self::assertSame(2, $detail['ownerListCount'], 'Wocheneinkauf und Altlast gehoeren demselben Account.');
        self::assertSame('plaintext', $detail['encryptionState']);
        self::assertSame(1, $detail['userCount']);
        self::assertSame(2, $detail['itemCount']);
        self::assertSame(1, $detail['activeItemCount']);
        /** @var list<array<string, mixed>> $items */
        $items = $detail['items'];
        self::assertSame(
            ['Milch', 'Brot'],
            array_column($items, 'name'),
            'auch archivierte, in der Reihenfolge der App',
        );
        self::assertNull($items[0]['deletedAt']);
        self::assertNotNull($items[1]['deletedAt']);

        $raw = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString($accountA, $raw);
        self::assertStringContainsString(
            'no-store',
            (string) $this->client->getResponse()->headers->get('Cache-Control'),
        );
    }

    public function testDetailNeedsTheTokenAndAKnownList(): void
    {
        $this->seed();
        $listId = $this->listId('Wocheneinkauf');

        $this->client->request('GET', '/v1/admin/lists/' . $listId);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $this->adminRequest('/v1/admin/lists/' . self::uuid(), 'Bearer ' . self::ADMIN_TOKEN);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->adminRequest('/v1/admin/lists/kein-uuid', 'Bearer ' . self::ADMIN_TOKEN);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testOldHtmlPageIsGone(): void
    {
        // /admin/ liefert Caddy statisch aus (Vue-App); PHP kennt den Pfad nicht mehr.
        $this->adminRequest('/admin', 'Bearer ' . self::ADMIN_TOKEN);

        self::assertNotSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testNonAdminPathsStayUntouched(): void
    {
        // Die Admin-Firewall darf die normale API nicht beeinflussen.
        $this->client->request('GET', '/v1/health');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testFilterMatchesTheAccountReference(): void
    {
        $this->seed();
        $owner = (string) $this->getLists()['lists'][0]['owner']; // Drogerie gehoert Account B

        $body = $this->getLists('q=' . substr($owner, 0, 6));

        self::assertSame(1, $body['total']);
        /** @var list<array<string, mixed>> $lists */
        $lists = $body['lists'];
        self::assertSame(['Drogerie'], array_column($lists, 'name'));
    }

    public function testFilterTreatsLikeWildcardsLiterally(): void
    {
        $this->seed();

        // "%" und "_" duerfen nicht als Platzhalter alles treffen.
        self::assertSame(0, $this->getLists('q=%25')['total']);
        self::assertSame(0, $this->getLists('q=_')['total']);
        self::assertSame(0, $this->getLists('q=%5C')['total']);
    }

    public function testJsonEndpointSupportsTheNameFilter(): void
    {
        $this->seed();

        self::assertSame(1, $this->getLists('q=drog')['total']);
    }

    public function testJsonItemsAreOptIn(): void
    {
        $this->seed();

        foreach ($this->getLists()['lists'] as $list) {
            self::assertArrayNotHasKey('items', $list, 'Ohne includeItems keine Eintraege im JSON.');
        }

        $byName = $this->listsByName($this->getLists('includeItems=1'));
        self::assertSame(
            ['Milch'],
            array_column($byName['Wocheneinkauf']['items'], 'name'),
            'Archivierte standardmaessig nicht',
        );
        self::assertSame([], $byName['Drogerie']['items'], 'Eintraege bleiben bei ihrer Liste.');
        self::assertSame([], $byName['Altlast']['items']);

        $withArchived = $this->listsByName($this->getLists('includeItems=1&includeArchivedItems=1'));
        $items = $withArchived['Wocheneinkauf']['items'];
        self::assertSame(['Milch', 'Brot'], array_column($items, 'name'));
        self::assertNull($items[0]['deletedAt']);
        self::assertSame('2026-02-01T10:00:02.000Z', $items[1]['deletedAt']);
        self::assertEquals(1.0, $items[0]['quantity'], 'JSON kennt nur number: 1.0 kommt als 1 an.');
        self::assertSame('St', $items[0]['unit']);
        self::assertFalse($items[0]['isChecked']);
        self::assertSame(
            [
                'id',
                'encrypted',
                'name',
                'quantity',
                'unit',
                'isChecked',
                'note',
                'sortOrder',
                'createdAt',
                'updatedAt',
                'deletedAt',
                'movedAt',
            ],
            array_keys($items[0]),
        );
    }

    public function testItemsDoNotExposeAccountIdentifiers(): void
    {
        [$accountA, $accountB] = $this->seed();

        $this->getLists('includeItems=1&includeArchivedItems=1');
        $raw = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString($accountA, $raw);
        self::assertStringNotContainsString($accountB, $raw);
    }

    public function testListsShowWhetherAndWithHowManyUsersTheyAreShared(): void
    {
        $this->seed();
        $owner = $this->accountOfList('Wocheneinkauf');
        $this->share($owner, $this->listId('Wocheneinkauf'), [self::uuid(), self::uuid()]);
        $this->share($this->accountOfList('Drogerie'), $this->listId('Drogerie'), []); // Code ohne Mitglied

        $lists = $this->listsByName($this->getLists());

        $shared = $lists['Wocheneinkauf'];
        self::assertTrue($shared['shared']);
        self::assertSame(2, $shared['memberCount'], 'Mitglieder ohne den Besitzer');
        self::assertSame(3, $shared['userCount'], 'Zugriff haben Besitzer + Mitglieder');
        self::assertTrue($shared['shareCodeActive']);
        self::assertSame(2, $shared['itemCount'], 'Mitglieder duerfen die Artikelzaehler nicht vervielfachen (JOIN).');
        self::assertSame(1, $shared['activeItemCount']);

        $prepared = $lists['Drogerie'];
        self::assertFalse(
            $prepared['shared'],
            'Ein Code ohne Mitglied ist noch nicht geteilt (wie auf der Statusseite).',
        );
        self::assertSame(0, $prepared['memberCount']);
        self::assertSame(1, $prepared['userCount']);
        self::assertTrue($prepared['shareCodeActive']);

        $private = $lists['Altlast'];
        self::assertFalse($private['shared']);
        self::assertSame(1, $private['userCount']);
        self::assertFalse($private['shareCodeActive']);
    }

    public function testEndingTheSharingResetsTheDisplay(): void
    {
        $this->seed();
        $owner = $this->accountOfList('Wocheneinkauf');
        $listId = $this->listId('Wocheneinkauf');
        $this->share($owner, $listId, [self::uuid()]);

        $this->client->request('DELETE', '/v1/lists/' . $listId . '/share', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $owner,
        ]);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());

        $list = $this->listsByName($this->getLists())['Wocheneinkauf'];
        self::assertFalse($list['shared']);
        self::assertSame(1, $list['userCount']);
        self::assertFalse($list['shareCodeActive']);
    }

    public function testShareCodeAndMemberAccountsAreNeverExposed(): void
    {
        $this->seed();
        $member = self::uuid();
        $code = $this->share($this->accountOfList('Wocheneinkauf'), $this->listId('Wocheneinkauf'), [$member]);

        $this->getLists('includeItems=1');
        $json = (string) $this->client->getResponse()->getContent();
        $this->getJson('/v1/admin/lists/' . $this->listId('Wocheneinkauf'));
        $detail = (string) $this->client->getResponse()->getContent();

        foreach ([$json, $detail] as $body) {
            self::assertStringNotContainsString($code, $body, 'Der Teilen-Code ist ein Zugangs-Secret.');
            self::assertStringNotContainsString($member, $body);
            self::assertStringNotContainsString(hash('sha256', $member), $body);
        }
    }

    /**
     * Zwei Accounts, drei Listen, davon eine geloescht. Gibt die Account-UUIDs zurueck.
     *
     * @return array{string, string}
     */
    private function seed(): array
    {
        $accountA = self::uuid();
        $accountB = self::uuid();
        $einkauf = self::uuid();
        $altlast = self::uuid();

        $this->sync($accountA, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => [
                'lists' => [
                    self::listRow($einkauf, '2026-02-01T10:00:00.000Z', 'Wocheneinkauf'),
                    self::listRow($altlast, '2026-01-15T10:00:00.000Z', 'Altlast', '2026-01-15T10:00:00.000Z'),
                ],
                'items' => [
                    self::itemRow(self::uuid(), $einkauf, '2026-02-01T10:00:01.000Z', 'Milch'),
                    // Spaeteres createdAt: bei gleicher sortOrder entscheidet sonst die zufaellige UUID.
                    array_merge(
                        self::itemRow(
                            self::uuid(),
                            $einkauf,
                            '2026-02-01T10:00:02.000Z',
                            'Brot',
                            '2026-02-01T10:00:02.000Z',
                        ),
                        ['createdAt' => '2026-01-02T00:00:00.000Z'],
                    ),
                ],
            ],
        ]);

        $this->sync($accountB, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [self::listRow(self::uuid(), '2026-03-01T10:00:00.000Z', 'Drogerie')]],
        ]);

        return [$accountA, $accountB];
    }

    /**
     * @return array<string, mixed>
     */
    private function getLists(string $query = ''): array
    {
        $this->adminRequest('/v1/admin/lists' . ('' === $query ? '' : '?' . $query), 'Bearer ' . self::ADMIN_TOKEN);

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, array<string, mixed>>
     */
    private function listsByName(array $body): array
    {
        $byName = [];
        /** @var list<array<string, mixed>> $lists */
        $lists = $body['lists'];
        foreach ($lists as $list) {
            $byName[(string) $list['name']] = $list;
        }

        return $byName;
    }

    /**
     * Teilt eine Liste ueber die echten Endpunkte (Code erzeugen, beitreten) und gibt den Code zurueck.
     *
     * @param list<string> $members Konto-UUIDs der Mitglieder
     */
    private function share(string $owner, string $listId, array $members): string
    {
        $this->client->request('POST', '/v1/lists/' . $listId . '/share', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $owner,
        ]);
        self::assertSame(
            200,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );
        /** @var array{code: string} $share */
        $share = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        foreach ($members as $member) {
            $this->sync($member, ['since' => 0, 'clientId' => self::uuid()]);
            $this->client->request('POST', '/v1/shares/' . $share['code'], server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $member,
            ]);
            self::assertSame(
                200,
                $this->client->getResponse()->getStatusCode(),
                (string) $this->client->getResponse()->getContent(),
            );
        }

        return $share['code'];
    }

    private function listId(string $name): string
    {
        return (string) $this->connection()->fetchOne('SELECT id FROM shopping_list WHERE name = :name', [
            'name' => $name,
        ]);
    }

    private function accountOfList(string $name): string
    {
        return (string) $this->connection()->fetchOne('SELECT user_id FROM shopping_list WHERE name = :name', [
            'name' => $name,
        ]);
    }

    /**
     * Namen der Listen in der gelieferten Reihenfolge.
     *
     * @return list<string|null>
     */
    private function names(string $query): array
    {
        /** @var list<array<string, mixed>> $lists */
        $lists = $this->getLists($query)['lists'];

        return array_map(static fn(array $list): ?string => null === $list['name']
            ? null
            : (string) $list['name'], $lists);
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $uri): array
    {
        $this->adminRequest($uri, 'Bearer ' . self::ADMIN_TOKEN);

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /** Attrappe eines Chiffretexts im Format `v1.<nonce>.<chiffretext>`. */
    private static function cipher(): string
    {
        $b64 = static fn(string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        return 'v1.' . $b64(random_bytes(12)) . '.' . $b64(random_bytes(40));
    }

    private function adminRequest(string $uri, string $authorization): void
    {
        $this->client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => $authorization]);
    }
}
