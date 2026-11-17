<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Live\LiveUpdates;
use App\Tests\Support\RecordingHub;
use Symfony\Component\Mercure\Update;

/**
 * Eintraege ueber die Admin-Schnittstelle loeschen (`DELETE /v1/admin/items/{id}`): als
 * Tombstone, der bei allen Teilnehmern ankommt.
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

    public function testDeleteNeedsTheAdminToken(): void
    {
        $this->client->request('DELETE', '/v1/admin/items/' . $this->itemId);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->deletedAt($this->itemId));
    }

    public function testRequestFromAnotherSiteIsRejectedEvenWithTheToken(): void
    {
        // Eine Header-Erweiterung haengt das Token auch an fremde Anfragen.
        foreach (['cross-site', 'same-site', 'none'] as $site) {
            $this->adminDelete('/v1/admin/items/' . $this->itemId, $site);
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $site);
        }

        self::assertNull($this->deletedAt($this->itemId));
    }

    public function testPostIsNotAccepted(): void
    {
        // Ein HTML-Formular kann nur GET und POST: beides loescht nichts.
        $this->client->request('POST', '/v1/admin/items/' . $this->itemId, server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::ADMIN_TOKEN,
        ]);

        self::assertSame(405, $this->client->getResponse()->getStatusCode());
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

        self::assertSame('deleted', $this->deleteConfirmed($this->itemId));
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
        self::assertSame('deleted', $this->deleteConfirmed($this->itemId));
        $version = $this->connection()->fetchOne('SELECT server_version FROM shopping_item WHERE id = :id', [
            'id' => $this->itemId,
        ]);
        RecordingHub::$updates = [];

        // Doppelt abgeschickt (Doppelklick, zweiter Tab): kein neuer Stand.
        self::assertSame('already-deleted', $this->deleteConfirmed($this->itemId));
        self::assertSame('already-deleted', $this->deleteConfirmed($this->archivedId));

        self::assertSame($version, $this->connection()->fetchOne('SELECT server_version FROM shopping_item WHERE id = :id', [
            'id' => $this->itemId,
        ]));
        self::assertSame([], RecordingHub::$updates);
    }

    public function testUnknownItemIsNotFound(): void
    {
        $this->adminDelete('/v1/admin/items/' . self::uuid());
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->adminDelete('/v1/admin/items/kein-uuid');
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

        self::assertSame('deleted', $this->deleteConfirmed($itemId));

        self::assertNotNull($this->deletedAt($itemId));
        self::assertSame($enc, $this->connection()->fetchOne('SELECT enc FROM shopping_item WHERE id = :id', [
            'id' => $itemId,
        ]));
    }

    // --- Helfer -----------------------------------------------------------

    /**
     * Loescht wie die Admin-Oberflaeche (DELETE von derselben Seite) und gibt `result` zurueck.
     */
    private function deleteConfirmed(string $itemId): string
    {
        $this->adminDelete('/v1/admin/items/' . $itemId, 'same-origin');
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

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
