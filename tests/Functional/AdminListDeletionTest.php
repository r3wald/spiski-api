<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Live\LiveUpdates;
use App\Tests\Support\RecordingHub;
use Symfony\Component\Mercure\Update;

/**
 * Ganze Listen ueber die Admin-Schnittstelle loeschen (`DELETE /v1/admin/lists/{id}`),
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

    public function testDeleteNeedsTheAdminToken(): void
    {
        $this->client->request('DELETE', '/v1/admin/lists/' . $this->listId);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->listDeletedAt($this->listId));
    }

    public function testRequestFromAnotherSiteIsRejectedEvenWithTheToken(): void
    {
        $this->adminDelete('/v1/admin/lists/' . $this->listId, 'cross-site');

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

        self::assertSame('deleted', $this->deleteConfirmed($this->listId));

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
        self::assertSame('deleted', $this->deleteConfirmed($this->listId));
        $version = $this->connection()->fetchOne('SELECT server_version FROM shopping_list WHERE id = :id', [
            'id' => $this->listId,
        ]);
        RecordingHub::$updates = [];

        self::assertSame('already-deleted', $this->deleteConfirmed($this->listId));
        self::assertSame('already-deleted', $this->deleteConfirmed($this->deletedListId));

        self::assertSame($version, $this->connection()->fetchOne('SELECT server_version FROM shopping_list WHERE id = :id', [
            'id' => $this->listId,
        ]));
        self::assertSame([], RecordingHub::$updates);
    }

    public function testUnknownListIsNotFound(): void
    {
        $this->adminDelete('/v1/admin/lists/' . self::uuid());
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->adminDelete('/v1/admin/lists/kein-uuid');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testDetailShowsTheDeletion(): void
    {
        $this->deleteConfirmed($this->listId);

        $this->client->request('GET', '/v1/admin/lists/' . $this->listId, server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::ADMIN_TOKEN,
        ]);
        /** @var array{deletedAt: ?string, activeItemCount: int} $detail */
        $detail = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertNotNull($detail['deletedAt']);
        self::assertSame(1, $detail['activeItemCount'], 'Die Eintraege bleiben, wie in der App.');
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

        self::assertSame('deleted', $this->deleteConfirmed($listId));

        self::assertNotNull($this->listDeletedAt($listId));
        self::assertSame($enc, $this->connection()->fetchOne('SELECT enc FROM shopping_list WHERE id = :id', [
            'id' => $listId,
        ]));
    }

    // --- Helfer -----------------------------------------------------------

    /**
     * Loescht wie die Admin-Oberflaeche (DELETE von derselben Seite) und gibt `result` zurueck.
     */
    private function deleteConfirmed(string $listId): string
    {
        $this->adminDelete('/v1/admin/lists/' . $listId, 'same-origin');
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{result: string} $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $body['result'];
    }

    private function adminDelete(string $path, ?string $secFetchSite = null): void
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer ' . self::ADMIN_TOKEN];
        if (null !== $secFetchSite) {
            $server['HTTP_SEC_FETCH_SITE'] = $secFetchSite;
        }
        $this->client->request('DELETE', $path, server: $server);
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
