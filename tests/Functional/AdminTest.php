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
        $this->adminRequest('/v1/admin/lists', 'Bearer '.self::ADMIN_TOKEN.'x');

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testNonBearerSchemeIsUnauthorized(): void
    {
        $this->adminRequest('/v1/admin/lists', 'Basic '.self::ADMIN_TOKEN);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testAccountUuidIsNotAnAdminToken(): void
    {
        // Ein gueltiger Nutzer-Token darf die Admin-Daten nicht oeffnen.
        $account = self::uuid();
        $this->sync($account, ['since' => 0, 'clientId' => self::uuid()]);

        $this->adminRequest('/v1/admin/lists', 'Bearer '.$account);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminTokenIsNotAnAccountToken(): void
    {
        // Umgekehrt: das Admin-Token ist keine UUID und legt keinen Account an.
        $this->client->request(
            'POST',
            '/v1/sync',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::ADMIN_TOKEN],
            content: '{"since":0,"clientId":"'.self::uuid().'"}',
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
        self::assertStringNotContainsString(hash('sha256', $accountA), $raw, 'Nur das gekuerzte Kuerzel, nicht der volle Hash.');
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
        $this->adminRequest('/v1/admin/lists?'.$query, 'Bearer '.self::ADMIN_TOKEN);

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
    }

    public function testNonAdminPathsStayUntouched(): void
    {
        // Die Admin-Firewall darf die normale API nicht beeinflussen.
        $this->client->request('GET', '/v1/health');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testPageWithoutTokenIsUnauthorized(): void
    {
        $this->client->request('GET', '/admin');

        $response = $this->client->getResponse();
        self::assertSame(401, $response->getStatusCode());
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
    }

    public function testPageWithWrongTokenIsUnauthorized(): void
    {
        $this->adminRequest('/admin', 'Bearer '.self::ADMIN_TOKEN.'x');

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testTokenInTheQueryStringIsNotAccepted(): void
    {
        // Das Token gehoert in den Header: in einer URL landet es in Logs und im Browserverlauf.
        $this->client->request('GET', '/admin?token='.self::ADMIN_TOKEN.'&access_token='.self::ADMIN_TOKEN);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testPageRendersListsAsHtmlWithBearerToken(): void
    {
        [$accountA, $accountB] = $this->seed();

        $html = $this->getPage('/admin');
        $response = $this->client->getResponse();

        self::assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        foreach (['Wocheneinkauf', 'Drogerie', 'Altlast', 'gelöscht', '3 Listen von 2 Accounts'] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
        self::assertStringContainsString('1 / 2', $html, 'aktive / gesamte Artikel der Liste Wocheneinkauf');

        // Keine Skripte und keine Account-UUIDs auf der Seite.
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString($accountA, $html);
        self::assertStringNotContainsString($accountB, $html);
        self::assertStringNotContainsString(self::ADMIN_TOKEN, $html, 'Das Token darf nie in der Seite stehen.');

        self::assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
        self::assertStringNotContainsString('script-src', (string) $response->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertFalse($response->headers->has('Set-Cookie'), 'Die Seite ist zustandslos: keine Session, kein Cookie.');
    }

    public function testTrailingSlashRedirectsToThePage(): void
    {
        $this->adminRequest('/admin/', 'Bearer '.self::ADMIN_TOKEN);

        $response = $this->client->getResponse();
        self::assertSame(301, $response->getStatusCode());
        self::assertStringEndsWith('/admin', (string) $response->headers->get('Location'));
    }

    public function testArrayQueryParameterIsABadRequest(): void
    {
        $this->adminRequest('/admin?q[]=x', 'Bearer '.self::ADMIN_TOKEN);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testPageEscapesUserControlledListNames(): void
    {
        $this->sync(self::uuid(), [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [self::listRow(self::uuid(), '2026-03-01T10:00:00.000Z', '<script>alert(1)</script>"\'&')]],
        ]);

        $html = $this->getPage('/admin');

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert', $html);
    }

    public function testPageFiltersByNameCaseInsensitively(): void
    {
        $this->seed();

        $html = $this->getPage('/admin?q=DROG');

        self::assertStringContainsString('Drogerie', $html);
        self::assertStringNotContainsString('Wocheneinkauf', $html);
        self::assertStringContainsString('1 Liste von 1 Account', $html);
    }

    public function testFilterMatchesTheAccountReference(): void
    {
        $this->seed();
        $owner = (string) $this->getLists()['lists'][0]['owner']; // Drogerie gehoert Account B

        $body = $this->getLists('q='.substr($owner, 0, 6));

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

    public function testPageCanHideDeletedLists(): void
    {
        $this->seed();

        $html = $this->getPage('/admin?hideDeleted=1');

        self::assertStringNotContainsString('Altlast', $html);
        self::assertStringContainsString('2 Listen von 2 Accounts', $html);
    }

    public function testPagePaginationLinksKeepTheFilter(): void
    {
        $this->seed();

        $first = $this->getPage('/admin?limit=1');
        self::assertStringContainsString('href="/admin?limit=1&amp;offset=1"', $first);
        self::assertStringNotContainsString('Zurück', $first);

        $second = $this->getPage('/admin?limit=1&offset=1&hideDeleted=1&q=o');
        self::assertStringContainsString('Zurück', $second);
        self::assertStringContainsString('hideDeleted=1', $second);
    }

    public function testPageRejectsInvalidParameters(): void
    {
        foreach (['limit=0', 'limit=501', 'offset=-1', 'q='.str_repeat('a', 101)] as $query) {
            $this->adminRequest('/admin?'.$query, 'Bearer '.self::ADMIN_TOKEN);
            self::assertSame(422, $this->client->getResponse()->getStatusCode(), $query);
        }
    }

    public function testJsonEndpointSupportsTheNameFilter(): void
    {
        $this->seed();

        self::assertSame(1, $this->getLists('q=drog')['total']);
    }

    public function testPageShowsActiveItemsAndHidesArchivedOnesByDefault(): void
    {
        $this->seed();

        $html = $this->getPage('/admin');

        self::assertStringContainsString('1 St Milch', $html, 'Menge, Einheit und Name wie in der App');
        self::assertStringContainsString('<summary>1 Eintrag · 1 archiviert, ausgeblendet</summary>', $html);
        self::assertStringNotContainsString('Brot', $html, 'Archivierte Eintraege sind standardmaessig ausgeblendet.');
        self::assertStringContainsString('<details>', $html);
        self::assertStringNotContainsString('<details open', $html);
        self::assertStringContainsString('offen: ', $html);
        self::assertStringNotContainsString('erledigt', $html, 'Ein nicht abgehakter Eintrag ist nicht erledigt.');
    }

    public function testPageCanShowArchivedItems(): void
    {
        $this->seed();

        $html = $this->getPage('/admin?archivedItems=1');

        self::assertStringContainsString('Brot', $html);
        self::assertStringContainsString('archiviert 2026-02-01 10:00:02', $html);
        self::assertStringContainsString('class="item archived"', $html);
        self::assertStringContainsString('<summary>2 Einträge</summary>', $html);
        self::assertStringNotContainsString('ausgeblendet', $html);
    }

    public function testItemsCanBeRenderedExpanded(): void
    {
        $this->seed();

        self::assertStringContainsString('<details open>', $this->getPage('/admin?open=1'));
    }

    public function testListWithOnlyArchivedItemsSaysSo(): void
    {
        $list = self::uuid();
        $this->sync(self::uuid(), [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => [
                'lists' => [self::listRow($list, '2026-03-01T10:00:00.000Z', 'Leergut')],
                'items' => [self::itemRow(self::uuid(), $list, '2026-03-01T10:00:01.000Z', 'Flaschen', '2026-03-01T10:00:01.000Z')],
            ],
        ]);

        $html = $this->getPage('/admin');

        self::assertStringContainsString('Keine aktiven Einträge · 1 archiviert, ausgeblendet', $html);
        self::assertStringNotContainsString('Flaschen', $html);
    }

    public function testItemsAreEscapedInThePage(): void
    {
        $list = self::uuid();
        $this->sync(self::uuid(), [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => [
                'lists' => [self::listRow($list, '2026-03-01T10:00:00.000Z', 'Liste')],
                'items' => [array_merge(
                    self::itemRow(self::uuid(), $list, '2026-03-01T10:00:01.000Z', '<img src=x onerror=alert(1)>'),
                    ['note' => '"><script>alert(2)</script>', 'unit' => '<b>'],
                )],
            ],
        ]);

        $html = $this->getPage('/admin');

        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;alert(2)&lt;/script&gt;', $html);
        self::assertStringContainsString('&lt;b&gt;', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function testItemsAreOrderedLikeInTheAppAndFormattedWithGermanDecimals(): void
    {
        $list = self::uuid();
        $this->sync(self::uuid(), [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => [
                'lists' => [self::listRow($list, '2026-03-01T10:00:00.000Z', 'Backen')],
                'items' => [
                    array_merge(self::itemRow(self::uuid(), $list, '2026-03-01T10:00:01.000Z', 'Mehl'), [
                        'sortOrder' => 2, 'quantity' => 0.5, 'unit' => 'kg', 'isChecked' => true, 'note' => 'Typ 405',
                    ]),
                    array_merge(self::itemRow(self::uuid(), $list, '2026-03-01T10:00:02.000Z', 'Zucker'), [
                        'sortOrder' => 1, 'quantity' => 2.0, 'unit' => null,
                    ]),
                ],
            ],
        ]);

        $html = $this->getPage('/admin');

        self::assertStringContainsString('0,5 kg Mehl', $html);
        self::assertStringContainsString('2 Zucker', $html, 'Ganze Mengen ohne Nachkommastellen.');
        self::assertLessThan(strpos($html, 'Mehl'), strpos($html, 'Zucker'), 'sortOrder 1 vor sortOrder 2');
        self::assertStringContainsString('<span class="tag">erledigt</span>', $html);
        self::assertStringContainsString('Notiz: Typ 405', $html);
    }

    public function testPaginationLinksKeepTheItemOptions(): void
    {
        $this->seed();

        $html = $this->getPage('/admin?limit=1&archivedItems=1&open=1');

        self::assertStringContainsString('archivedItems=1', $html);
        self::assertMatchesRegularExpression('/href="\/admin\?[^"]*open=1/', $html);
        self::assertStringContainsString('<input type="checkbox" name="archivedItems" value="1" checked>', $html);
    }

    public function testJsonItemsAreOptIn(): void
    {
        $this->seed();

        foreach ($this->getLists()['lists'] as $list) {
            self::assertArrayNotHasKey('items', $list, 'Ohne includeItems keine Eintraege im JSON.');
        }

        $byName = $this->listsByName($this->getLists('includeItems=1'));
        self::assertSame(['Milch'], array_column($byName['Wocheneinkauf']['items'], 'name'), 'Archivierte standardmaessig nicht');
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
        self::assertSame(['id', 'name', 'quantity', 'unit', 'isChecked', 'note', 'sortOrder', 'createdAt', 'updatedAt', 'deletedAt', 'movedAt'], array_keys($items[0]));
    }

    public function testItemsDoNotExposeAccountIdentifiers(): void
    {
        [$accountA, $accountB] = $this->seed();

        $this->getLists('includeItems=1&includeArchivedItems=1');
        $raw = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString($accountA, $raw);
        self::assertStringNotContainsString($accountB, $raw);
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
                        self::itemRow(self::uuid(), $einkauf, '2026-02-01T10:00:02.000Z', 'Brot', '2026-02-01T10:00:02.000Z'),
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
        $this->adminRequest('/v1/admin/lists'.('' === $query ? '' : '?'.$query), 'Bearer '.self::ADMIN_TOKEN);

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

    private function getPage(string $uri): string
    {
        $this->adminRequest($uri, 'Bearer '.self::ADMIN_TOKEN);

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return (string) $response->getContent();
    }

    private function adminRequest(string $uri, string $authorization): void
    {
        $this->client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => $authorization]);
    }
}
