<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Admin\AdminConfirmation;
use App\Live\LiveUpdates;
use App\Tests\Support\RecordingHub;
use Symfony\Component\Mercure\Update;

/**
 * Ganze Listen von der Admin-Seite aus loeschen: zweistufig wie bei Eintraegen, und
 * mit derselben Wirkung wie das Loeschen in der App (Tombstone nur auf der Liste,
 * wiederherstellbar ueber das Archiv der App).
 */
final class AdminListDeletionTest extends ApiTestCase
{
    /** Siehe ADMIN_TOKEN in .env.test. */
    private const ADMIN_TOKEN = 'test-admin-token-0123456789abcdef0123456789';

    private string $owner;
    private string $listId;
    private string $itemId;
    private string $deletedListId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = self::uuid();
        $this->listId = self::uuid();
        $this->itemId = self::uuid();
        $this->deletedListId = self::uuid();

        $this->sync($this->owner, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => [
                'lists' => [
                    self::listRow($this->listId, '2026-02-01T10:00:00.000Z', 'Wocheneinkauf'),
                    self::listRow(
                        $this->deletedListId,
                        '2026-02-01T10:00:00.000Z',
                        'Altliste',
                        '2026-02-01T10:00:00.000Z',
                    ),
                ],
                'items' => [
                    self::itemRow($this->itemId, $this->listId, '2026-02-01T10:00:01.000Z', 'Hafermilch'),
                    self::itemRow(
                        self::uuid(),
                        $this->listId,
                        '2026-02-01T10:00:01.000Z',
                        'Alt',
                        '2026-02-01T10:00:02.000Z',
                    ),
                ],
            ],
        ]);
        RecordingHub::$updates = [];
    }

    public function testOverviewOffersDeleteOnlyForActiveLists(): void
    {
        $html = $this->adminHtml('/admin?q=liste');
        self::assertStringNotContainsString('/admin/lists/' . $this->deletedListId . '/delete', $html);

        $html = $this->adminHtml('/admin?q=woche');
        self::assertStringContainsString(
            'href="/admin/lists/' . $this->listId . '/delete?limit=50&amp;offset=0&amp;q=woche"',
            $html,
        );
    }

    public function testConfirmationPageNeedsTheAdminToken(): void
    {
        $this->client->request('GET', '/admin/lists/' . $this->listId . '/delete');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/admin/lists/' . $this->listId . '/delete', ['confirm' => 'x']);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->listDeletedAt($this->listId));
    }

    public function testConfirmationPageShowsTheListAndChangesNothing(): void
    {
        $html = $this->adminHtml('/admin/lists/' . $this->listId . '/delete?q=woche');

        self::assertStringContainsString('Diese Liste wirklich löschen?', $html);
        self::assertStringContainsString('Wocheneinkauf', $html);
        self::assertStringContainsString('1 aktiv', $html);
        self::assertStringContainsString('(+ 1 archiviert)', $html);
        self::assertStringContainsString('1 Nutzer (nicht geteilt)', $html);
        self::assertStringContainsString('Archiv der App', $html);
        self::assertStringContainsString(
            'method="post" action="/admin/lists/' . $this->listId . '/delete?limit=50&amp;offset=0&amp;q=woche"',
            $html,
        );

        self::assertNull($this->listDeletedAt($this->listId), 'Die Bestaetigungsseite allein loescht nichts.');
        self::assertSame([], RecordingHub::$updates);
    }

    public function testDeleteWithoutValidConfirmationIsForbidden(): void
    {
        $this->adminPost('/admin/lists/' . $this->listId . '/delete', []);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        // Ein Token fuer "Eintrag loeschen" mit derselben ID gilt nicht fuer eine Liste.
        $this->adminPost('/admin/lists/' . $this->listId . '/delete', ['confirm' => $this->issue(
            'delete-item',
            $this->listId,
        )]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        // Und das Token einer anderen Liste auch nicht.
        $this->adminPost('/admin/lists/' . $this->listId . '/delete', [
            'confirm' => $this->confirmToken($this->deletedListId),
        ]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        self::assertNull($this->listDeletedAt($this->listId));
    }

    public function testFormFromAnotherSiteIsRejectedEvenWithAValidToken(): void
    {
        $this->adminPost(
            '/admin/lists/' . $this->listId . '/delete',
            ['confirm' => $this->confirmToken($this->listId)],
            'cross-site',
        );

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->listDeletedAt($this->listId));
    }

    public function testConfirmedDeleteRemovesTheListForEveryParticipant(): void
    {
        $member = self::uuid();
        $this->shareWith($member);
        $ownerCursor = $this->cursor($this->owner);
        $memberCursor = $this->cursor($member);
        RecordingHub::$updates = [];

        $this->adminPost(
            '/admin/lists/' . $this->listId . '/delete?q=woche',
            ['confirm' => $this->confirmToken($this->listId)],
            'same-origin',
        );

        $response = $this->client->getResponse();
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin?limit=50&offset=0&q=woche&notice=list-deleted', $response->headers->get('Location'));

        foreach ([$this->owner => $ownerCursor, $member => $memberCursor] as $account => $cursor) {
            $pull = $this->sync($account, ['since' => $cursor, 'clientId' => self::uuid()]);
            $row = self::findRow($pull, 'lists', $this->listId);
            self::assertNotNull($row, 'Der Tombstone muss bei jedem Teilnehmer ankommen.');
            self::assertNotNull($row['deletedAt']);
            self::assertSame($row['deletedAt'], $row['updatedAt']);
            self::assertSame([], $pull['revokedLists'], 'Geloescht ist nicht entzogen: das Teilen bleibt.');
        }

        // Wie in der App: nur die Liste bekommt den Tombstone, ihre Artikel bleiben.
        self::assertNull($this->connection()->fetchOne('SELECT deleted_at FROM shopping_item WHERE id = :id', [
            'id' => $this->itemId,
        ]));
        self::assertSame(
            1,
            (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM list_member WHERE list_id = :id', [
                'id' => $this->listId,
            ]),
        );

        self::assertEqualsCanonicalizing(
            [$this->topic($this->owner) => null, $this->topic($member) => null],
            $this->recordedUpdates(),
        );

        self::assertStringContainsString('Die Liste wurde gelöscht.', $this->adminHtml('/admin?notice=list-deleted'));
    }

    public function testOldStateCannotBringTheListBackButTheOwnerCanRestoreIt(): void
    {
        $this->deleteConfirmed($this->listId);
        $client = self::uuid();

        // Ein Geraet mit dem alten Stand verliert den Vergleich.
        $push = $this->sync($this->owner, [
            'since' => 0,
            'clientId' => $client,
            'changes' => ['lists' => [self::listRow($this->listId, '2026-02-01T10:00:00.000Z', 'Wocheneinkauf')]],
        ]);
        self::assertSame('stale', self::findPushResult($push, 'lists', $this->listId)['reason'] ?? null);
        self::assertNotNull($this->listDeletedAt($this->listId));

        // Wiederherstellen aus dem Archiv der App: spaeterer Zeitstempel, deletedAt leer.
        $restoredAt = new \DateTimeImmutable('+1 second', new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        $push = $this->sync($this->owner, [
            'since' => 0,
            'clientId' => $client,
            'changes' => ['lists' => [self::listRow($this->listId, $restoredAt, 'Wocheneinkauf')]],
        ]);
        self::assertSame(
            ['id' => $this->listId, 'accepted' => true],
            self::findPushResult($push, 'lists', $this->listId),
        );
        self::assertNull($this->listDeletedAt($this->listId));
    }

    public function testRepeatedDeleteChangesNothing(): void
    {
        $token = $this->confirmToken($this->listId);
        $this->adminPost('/admin/lists/' . $this->listId . '/delete', ['confirm' => $token]);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $version = $this->connection()->fetchOne('SELECT server_version FROM shopping_list WHERE id = :id', [
            'id' => $this->listId,
        ]);
        RecordingHub::$updates = [];

        $this->adminPost('/admin/lists/' . $this->listId . '/delete', ['confirm' => $token]);

        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertStringEndsWith(
            '&notice=list-already-deleted',
            (string) $this->client->getResponse()->headers->get('Location'),
        );
        self::assertSame($version, $this->connection()->fetchOne('SELECT server_version FROM shopping_list WHERE id = :id', [
            'id' => $this->listId,
        ]));
        self::assertSame([], RecordingHub::$updates);
    }

    public function testConfirmationPageForADeletedListOffersNoButton(): void
    {
        $html = $this->adminHtml('/admin/lists/' . $this->deletedListId . '/delete');

        self::assertStringContainsString('bereits gelöscht', $html);
        self::assertStringNotContainsString('<form', $html);
    }

    public function testUnknownListIsNotFound(): void
    {
        $unknown = self::uuid();

        $this->adminRequest('GET', '/admin/lists/' . $unknown . '/delete');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->adminPost('/admin/lists/' . $unknown . '/delete', ['confirm' => $this->issue('delete-list', $unknown)]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->adminRequest('GET', '/admin/lists/kein-uuid/delete');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testEncryptedListCanBeDeletedWithoutTouchingItsCiphertext(): void
    {
        $account = self::uuid();
        $listId = self::uuid();
        $enc = self::cipher();

        $this->protocol2('/v1/me/encryption/commit', $account, [
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
        $this->protocol2('/v1/sync', $account, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [$list + ['enc' => $enc, 'wrappedKey' => self::cipher()]]],
        ]);
        self::assertSame(
            200,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );

        self::assertStringContainsString('🔒 verschlüsselt', $this->adminHtml('/admin/lists/' . $listId . '/delete'));

        $this->deleteConfirmed($listId);

        self::assertNotNull($this->listDeletedAt($listId));
        self::assertSame($enc, $this->connection()->fetchOne('SELECT enc FROM shopping_list WHERE id = :id', [
            'id' => $listId,
        ]));
    }

    // --- Helfer -----------------------------------------------------------

    private function deleteConfirmed(string $listId): void
    {
        $this->adminPost(
            '/admin/lists/' . $listId . '/delete',
            ['confirm' => $this->confirmToken($listId)],
            'same-origin',
        );
        self::assertSame(
            303,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    /** Token so, wie der Browser es bekommt: aus der Bestaetigungsseite (auch fuer schon geloeschte nicht vorhanden). */
    private function confirmToken(string $listId): string
    {
        $html = $this->adminHtml('/admin/lists/' . $listId . '/delete');

        return 1 === preg_match('/name="confirm" value="([^"]+)"/', $html, $matches)
            ? $matches[1]
            : $this->issue('delete-list', $listId);
    }

    private function issue(string $action, string $id): string
    {
        /** @var AdminConfirmation $confirmation */
        $confirmation = static::getContainer()->get(AdminConfirmation::class);

        return $confirmation->issue($action, $id, new \DateTimeImmutable());
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

    private function listDeletedAt(string $listId): ?string
    {
        /** @var string|null|false $value */
        $value = $this->connection()->fetchOne('SELECT deleted_at FROM shopping_list WHERE id = :id', [
            'id' => $listId,
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
    private function protocol2(string $path, string $account, array $body): void
    {
        $this->client->request(
            'POST',
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
