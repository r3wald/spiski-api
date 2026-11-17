<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Live\LiveUpdates;
use App\Tests\Support\RecordingHub;
use Symfony\Component\Mercure\Update;

/**
 * Eintraege von der Admin-Seite aus loeschen: zweistufig (Bestaetigungsseite, dann
 * POST mit Bestaetigungstoken) und als Tombstone, der bei allen Teilnehmern ankommt.
 */
final class AdminItemDeletionTest extends ApiTestCase
{
    /** Siehe ADMIN_TOKEN in .env.test. */
    private const ADMIN_TOKEN = 'test-admin-token-0123456789abcdef0123456789';

    private string $owner;
    private string $listId;
    private string $itemId;
    private string $archivedId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = self::uuid();
        $this->listId = self::uuid();
        $this->itemId = self::uuid();
        $this->archivedId = self::uuid();

        $this->sync($this->owner, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => [
                'lists' => [self::listRow($this->listId, '2026-02-01T10:00:00.000Z', 'Wocheneinkauf')],
                'items' => [
                    self::itemRow($this->itemId, $this->listId, '2026-02-01T10:00:01.000Z', 'Hafermilch'),
                    self::itemRow(
                        $this->archivedId,
                        $this->listId,
                        '2026-02-01T10:00:01.000Z',
                        'Altlast',
                        '2026-02-01T10:00:02.000Z',
                    ),
                ],
            ],
        ]);
        RecordingHub::$updates = [];
    }

    public function testOverviewOffersDeleteOnlyForActiveItems(): void
    {
        $html = $this->adminHtml('/admin?archivedItems=1&q=woche');

        self::assertStringContainsString(
            'href="/admin/items/' . $this->itemId . '/delete?limit=50&amp;offset=0&amp;q=woche&amp;archivedItems=1"',
            $html,
        );
        self::assertStringNotContainsString('/admin/items/' . $this->archivedId . '/delete', $html);
    }

    public function testConfirmationPageNeedsTheAdminToken(): void
    {
        $this->client->request('GET', '/admin/items/' . $this->itemId . '/delete');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/admin/items/' . $this->itemId . '/delete', ['confirm' => 'x']);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->deletedAt($this->itemId));
    }

    public function testConfirmationPageShowsTheItemAndChangesNothing(): void
    {
        $html = $this->adminHtml('/admin/items/' . $this->itemId . '/delete?q=woche');

        self::assertStringContainsString('Diesen Eintrag wirklich löschen?', $html);
        self::assertStringContainsString('1 St Hafermilch', $html);
        self::assertStringContainsString('Wocheneinkauf', $html);
        self::assertStringContainsString(
            'method="post" action="/admin/items/' . $this->itemId . '/delete?limit=50&amp;offset=0&amp;q=woche"',
            $html,
        );
        self::assertStringContainsString('href="/admin?limit=50&amp;offset=0&amp;q=woche">Abbrechen', $html);
        self::assertStringContainsString(
            "form-action 'self'",
            (string) $this->client->getResponse()->headers->get('Content-Security-Policy'),
        );
        self::assertStringContainsString(
            'no-store',
            (string) $this->client->getResponse()->headers->get('Cache-Control'),
        );

        self::assertNull($this->deletedAt($this->itemId), 'Die Bestaetigungsseite allein loescht nichts.');
        self::assertSame([], RecordingHub::$updates);
    }

    public function testDeleteWithoutConfirmationIsForbidden(): void
    {
        $this->adminPost('/admin/items/' . $this->itemId . '/delete', []);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->adminPost('/admin/items/' . $this->itemId . '/delete', [
            'confirm' => '9999999999.' . str_repeat('0', 64),
        ]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        self::assertNull($this->deletedAt($this->itemId));
    }

    public function testConfirmationOfAnotherItemDoesNotCount(): void
    {
        $other = self::uuid();
        $this->sync($this->owner, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['items' => [self::itemRow($other, $this->listId, '2026-02-01T10:00:03.000Z')]],
        ]);

        $this->adminPost('/admin/items/' . $this->itemId . '/delete', ['confirm' => $this->confirmToken($other)]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->deletedAt($this->itemId));
    }

    public function testFormFromAnotherSiteIsRejectedEvenWithAValidToken(): void
    {
        $token = $this->confirmToken($this->itemId);

        $this->adminPost('/admin/items/' . $this->itemId . '/delete', ['confirm' => $token], 'cross-site');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->deletedAt($this->itemId));
    }

    public function testConfirmedDeleteArchivesTheItemForEveryParticipant(): void
    {
        $member = self::uuid();
        $this->shareWith($member);
        $ownerCursor = $this->cursor($this->owner);
        $memberCursor = $this->cursor($member);
        $before = (string) $this->connection()->fetchOne('SELECT updated_at FROM shopping_item WHERE id = :id', [
            'id' => $this->itemId,
        ]);
        RecordingHub::$updates = [];

        $this->adminPost(
            '/admin/items/' . $this->itemId . '/delete?q=woche&open=1',
            ['confirm' => $this->confirmToken($this->itemId)],
            'same-origin',
        );

        $response = $this->client->getResponse();
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin?limit=50&offset=0&q=woche&open=1&notice=deleted', $response->headers->get('Location'));
        self::assertNotNull($this->deletedAt($this->itemId));

        foreach ([$this->owner => $ownerCursor, $member => $memberCursor] as $account => $cursor) {
            $pull = $this->sync($account, ['since' => $cursor, 'clientId' => self::uuid()]);
            $row = self::findRow($pull, 'items', $this->itemId);
            self::assertNotNull($row, 'Der Tombstone muss bei jedem Teilnehmer ankommen.');
            self::assertNotNull($row['deletedAt']);
            self::assertSame($row['deletedAt'], $row['updatedAt']);
            self::assertGreaterThan(
                new \DateTimeImmutable($before),
                new \DateTimeImmutable((string) $row['updatedAt']),
            );
            self::assertFalse(
                $row['changedByOther'],
                'Kein Konto hat geaendert: keine Benachrichtigung "von anderen".',
            );
        }

        // Live-Update an beide Konten, ohne Herkunftsgeraet.
        self::assertEqualsCanonicalizing(
            [$this->topic($this->owner) => null, $this->topic($member) => null],
            $this->recordedUpdates(),
        );

        self::assertStringContainsString('Der Eintrag wurde gelöscht.', $this->adminHtml('/admin?notice=deleted'));
    }

    public function testDeviceWithTheOldStateCannotBringTheItemBack(): void
    {
        $this->deleteConfirmed($this->itemId);

        // Ein Geraet, das den Tombstone noch nicht kennt, pusht seinen alten Stand.
        $push = $this->sync($this->owner, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['items' => [self::itemRow(
                $this->itemId,
                $this->listId,
                '2026-02-01T10:00:01.000Z',
                'Hafermilch',
            )]],
        ]);

        self::assertSame(
            ['id' => $this->itemId, 'accepted' => false, 'reason' => 'stale'],
            self::findPushResult($push, 'items', $this->itemId),
        );
        self::assertNotNull(
            self::findRow($push, 'items', $this->itemId)['deletedAt'] ?? null,
            'Das Echo liefert den Tombstone.',
        );
        self::assertNotNull($this->deletedAt($this->itemId));
    }

    public function testItemWithAClockAheadIsStillDeleted(): void
    {
        $ahead = self::uuid();
        $future = new \DateTimeImmutable('+4 minutes', new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        $this->sync($this->owner, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['items' => [self::itemRow($ahead, $this->listId, $future)]],
        ]);

        $this->deleteConfirmed($ahead);

        $updatedAt = (string) $this->connection()->fetchOne('SELECT updated_at FROM shopping_item WHERE id = :id', [
            'id' => $ahead,
        ]);
        self::assertGreaterThan(new \DateTimeImmutable($future), new \DateTimeImmutable($updatedAt));
    }

    public function testRepeatedDeleteChangesNothing(): void
    {
        $token = $this->confirmToken($this->itemId);
        $this->adminPost('/admin/items/' . $this->itemId . '/delete', ['confirm' => $token]);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $version = $this->connection()->fetchOne('SELECT server_version FROM shopping_item WHERE id = :id', [
            'id' => $this->itemId,
        ]);
        RecordingHub::$updates = [];

        // Doppelt abgeschickt (Zurueck-Taste, Neuladen): kein neuer Stand.
        $this->adminPost('/admin/items/' . $this->itemId . '/delete', ['confirm' => $token]);

        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertStringEndsWith(
            '&notice=already-deleted',
            (string) $this->client->getResponse()->headers->get('Location'),
        );
        self::assertSame($version, $this->connection()->fetchOne('SELECT server_version FROM shopping_item WHERE id = :id', [
            'id' => $this->itemId,
        ]));
        self::assertSame([], RecordingHub::$updates);
    }

    public function testConfirmationPageForAnArchivedItemOffersNoButton(): void
    {
        $html = $this->adminHtml('/admin/items/' . $this->archivedId . '/delete');

        self::assertStringContainsString('bereits archiviert', $html);
        self::assertStringNotContainsString('<form', $html);
    }

    public function testUnknownItemIsNotFound(): void
    {
        $unknown = self::uuid();

        $this->adminRequest('GET', '/admin/items/' . $unknown . '/delete');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->adminPost('/admin/items/' . $unknown . '/delete', ['confirm' => $this->confirmTokenFor($unknown)]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->adminRequest('GET', '/admin/items/kein-uuid/delete');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testEncryptedItemCanBeDeletedWithoutTouchingItsCiphertext(): void
    {
        $account = self::uuid();
        $listId = self::uuid();
        $itemId = self::uuid();
        $enc = self::cipher();

        $this->protocol2('POST', '/v1/me/encryption/commit', $account, [
            'clientId' => self::uuid(),
            'keyCheck' => self::cipher(),
        ]);
        self::assertSame(
            200,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );

        $list = self::listRow($listId, '2026-06-01T10:00:00.000Z');
        unset($list['name']);
        $item = self::itemRow($itemId, $listId, '2026-06-01T10:00:01.000Z');
        unset($item['name'], $item['quantity'], $item['unit'], $item['note']);
        $this->protocol2('POST', '/v1/sync', $account, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => [
                'lists' => [$list + ['enc' => self::cipher(), 'wrappedKey' => self::cipher()]],
                'items' => [$item + ['enc' => $enc]],
            ],
        ]);
        self::assertSame(
            200,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );

        self::assertStringContainsString('🔒 verschlüsselt', $this->adminHtml('/admin/items/' . $itemId . '/delete'));

        $this->deleteConfirmed($itemId);

        self::assertNotNull($this->deletedAt($itemId));
        self::assertSame($enc, $this->connection()->fetchOne('SELECT enc FROM shopping_item WHERE id = :id', [
            'id' => $itemId,
        ]));
    }

    public function testUnknownNoticeIsIgnored(): void
    {
        $html = $this->adminHtml('/admin?notice=%3Cscript%3E');

        self::assertStringNotContainsString('class="notice"', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    // --- Helfer -----------------------------------------------------------

    private function deleteConfirmed(string $itemId): void
    {
        $this->adminPost(
            '/admin/items/' . $itemId . '/delete',
            ['confirm' => $this->confirmToken($itemId)],
            'same-origin',
        );
        self::assertSame(
            303,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    /** Token so, wie der Browser es bekommt: aus der Bestaetigungsseite. */
    private function confirmToken(string $itemId): string
    {
        $html = $this->adminHtml('/admin/items/' . $itemId . '/delete');
        self::assertSame(1, preg_match('/name="confirm" value="([^"]+)"/', $html, $matches));

        return $matches[1];
    }

    /** Fuer Eintraege ohne Bestaetigungsseite (unbekannt): direkt erzeugt. */
    private function confirmTokenFor(string $itemId): string
    {
        /** @var \App\Admin\AdminConfirmation $confirmation */
        $confirmation = static::getContainer()->get(\App\Admin\AdminConfirmation::class);

        return $confirmation->issue('delete-item', $itemId, new \DateTimeImmutable());
    }

    private function adminHtml(string $path): string
    {
        $this->adminRequest('GET', $path);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return (string) $response->getContent();
    }

    private function adminRequest(string $method, string $path): void
    {
        $this->client->request($method, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . self::ADMIN_TOKEN]);
    }

    /**
     * @param array<string, string> $form
     */
    private function adminPost(string $path, array $form, ?string $secFetchSite = null): void
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer ' . self::ADMIN_TOKEN];
        if (null !== $secFetchSite) {
            $server['HTTP_SEC_FETCH_SITE'] = $secFetchSite;
        }
        $this->client->request('POST', $path, $form, server: $server);
    }

    private function deletedAt(string $itemId): ?string
    {
        /** @var string|null|false $value */
        $value = $this->connection()->fetchOne('SELECT deleted_at FROM shopping_item WHERE id = :id', [
            'id' => $itemId,
        ]);

        return false === $value ? null : $value;
    }

    private function shareWith(string $member): void
    {
        $this->client->request('POST', '/v1/lists/' . $this->listId . '/share', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->owner,
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{code: string} $share */
        $share = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $this->sync($member, ['since' => 0, 'clientId' => self::uuid()]);
        $this->client->request('POST', '/v1/shares/' . $share['code'], server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $member,
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    private function cursor(string $account): int
    {
        return (int) $this->sync($account, ['since' => 0, 'clientId' => self::uuid()])['serverCursor'];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function protocol2(string $method, string $path, string $account, array $body): void
    {
        $this->client->request(
            $method,
            $path,
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $account,
                'HTTP_X_SPISKI_PROTOCOL' => '2',
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    /** Attrappe eines Chiffretexts im Format `v1.<nonce>.<chiffretext>`. */
    private static function cipher(): string
    {
        $b64 = static fn(string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        return 'v1.' . $b64(random_bytes(12)) . '.' . $b64(random_bytes(40));
    }

    /**
     * @return array<string, ?string> Topic => origin
     */
    private function recordedUpdates(): array
    {
        $recorded = [];
        /** @var list<Update> $updates */
        $updates = RecordingHub::$updates;
        foreach ($updates as $update) {
            /** @var array{origin: ?string} $data */
            $data = json_decode($update->getData(), true, 512, \JSON_THROW_ON_ERROR);
            $recorded[$update->getTopics()[0]] = $data['origin'];
        }

        return $recorded;
    }

    private function topic(string $account): string
    {
        /** @var LiveUpdates $live */
        $live = static::getContainer()->get(LiveUpdates::class);

        return $live->topicFor($account);
    }
}
