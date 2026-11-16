<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Ende-zu-Ende-Verschluesselung (CLAUDE.md 14): Zustaende eines Kontos,
 * Protokoll-Header, Migration, Teilen verschluesselter Listen, Frist und
 * Loeschen des Klartexts.
 *
 * Der Server prueft nur das Format; die Chiffretexte hier sind Attrappen.
 */
final class EncryptionTest extends ApiTestCase
{
    private const DEADLINE_ENV = 'SPISKI_ENCRYPTION_DEADLINE';
    private const ADMIN_TOKEN = 'test-admin-token-0123456789abcdef0123456789';

    protected function tearDown(): void
    {
        $this->setDeadline('');
        parent::tearDown();
    }

    public function testNewAccountIsPlaintext(): void
    {
        $account = self::uuid();

        self::assertSame(['state' => 'plaintext', 'keyCheck' => null], $this->call('GET', '/v1/me/encryption', $account));
        self::assertSame(200, $this->httpStatus());
    }

    public function testEncryptionEndpointsNeedProtocol2(): void
    {
        $account = self::uuid();

        $this->call('GET', '/v1/me/encryption', $account, protocol: null);
        self::assertSame(426, $this->httpStatus());
        self::assertSame('https://spiski.app/problems/upgrade-required', $this->problemType());

        $this->call('POST', '/v1/me/encryption/begin', $account, ['clientId' => self::uuid(), 'keyCheck' => self::cipher()], protocol: '1');
        self::assertSame(426, $this->httpStatus());
    }

    public function testOldAppsKeepWorkingForPlaintextAccounts(): void
    {
        $account = self::uuid();
        $listId = self::uuid();

        $response = $this->sync($account, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [self::listRow($listId, '2026-06-01T10:00:00.000Z', 'Wocheneinkauf')]],
        ]);

        $list = self::findRow($response, 'lists', $listId);
        self::assertNotNull($list);
        self::assertSame('Wocheneinkauf', $list['name']);
        self::assertArrayNotHasKey('enc', $list);
    }

    public function testFullMigration(): void
    {
        $account = self::uuid();
        $oldDevice = self::uuid();
        $migrator = self::uuid();
        $listId = self::uuid();
        $itemId = self::uuid();

        // Bestand aus der alten App.
        $this->sync($account, [
            'since' => 0,
            'clientId' => $oldDevice,
            'changes' => [
                'lists' => [self::listRow($listId, '2026-06-01T10:00:00.000Z', 'Wocheneinkauf')],
                'items' => [self::itemRow($itemId, $listId, '2026-06-01T10:00:01.000Z', 'Milch')],
            ],
        ]);

        $keyCheck = self::cipher();
        self::assertSame(['state' => 'migrating', 'keyCheck' => $keyCheck], $this->begin($account, $migrator, $keyCheck));

        // Ab jetzt: alte App ausgesperrt, andere neue Geraete warten.
        $this->request($account, ['since' => 0, 'clientId' => $oldDevice]);
        self::assertSame(426, $this->httpStatus());
        $this->encSyncRaw($account, ['since' => 0, 'clientId' => self::uuid()]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('https://spiski.app/problems/migration-in-progress', $this->problemType());

        // Commit vor dem Hochladen: Klartext liegt noch auf dem Server.
        $this->call('POST', '/v1/me/encryption/commit', $account, ['clientId' => $migrator]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('https://spiski.app/problems/encryption-incomplete', $this->problemType());
        self::assertStringContainsString('2 Klartext-Zeilen', $this->problemDetail());

        // Das migrierende Geraet sieht den Klartext weiterhin.
        $pull = $this->encSync($account, ['since' => 0, 'clientId' => $migrator]);
        self::assertSame('Wocheneinkauf', self::findRow($pull, 'lists', $listId)['name'] ?? null);

        // Gleicher (sogar aelterer) Zeitstempel: ersetzt den Klartext trotzdem.
        $listEnc = self::cipher();
        $itemEnc = self::cipher();
        $wrappedKey = self::cipher();
        $pushed = $this->encSync($account, [
            'since' => 0,
            'clientId' => $migrator,
            'changes' => [
                'lists' => [self::encListRow($listId, '2026-06-01T09:00:00.000Z', $listEnc, $wrappedKey)],
                'items' => [self::encItemRow($itemId, $listId, '2026-06-01T10:00:01.000Z', $itemEnc)],
            ],
        ]);
        self::assertTrue(self::findPushResult($pushed, 'lists', $listId)['accepted'] ?? false);
        self::assertTrue(self::findPushResult($pushed, 'items', $itemId)['accepted'] ?? false);

        /** @var array{name: string, enc: string, wrapped_key: string} $row */
        $row = $this->connection()->fetchAssociative('SELECT name, enc, wrapped_key FROM shopping_list WHERE id = :id', ['id' => $listId]);
        self::assertSame(['name' => '', 'enc' => $listEnc, 'wrapped_key' => $wrappedKey], $row);
        /** @var array{name: string, quantity: mixed, unit: mixed, note: mixed} $item */
        $item = $this->connection()->fetchAssociative('SELECT name, quantity, unit, note FROM shopping_item WHERE id = :id', ['id' => $itemId]);
        self::assertSame(['name' => '', 'quantity' => null, 'unit' => null, 'note' => null], $item, 'Kein Klartext bleibt stehen.');

        self::assertSame(['state' => 'encrypted', 'keyCheck' => $keyCheck], $this->call('POST', '/v1/me/encryption/commit', $account, ['clientId' => $migrator]));

        // Ein weiteres Geraet mit Konto-Code: bekommt nur Chiffretext.
        $pull = $this->encSync($account, ['since' => 0, 'clientId' => self::uuid()]);
        $list = self::findRow($pull, 'lists', $listId);
        self::assertNotNull($list);
        self::assertArrayNotHasKey('name', $list);
        self::assertSame($listEnc, $list['enc']);
        self::assertSame($wrappedKey, $list['wrappedKey']);
        $pulledItem = self::findRow($pull, 'items', $itemId);
        self::assertNotNull($pulledItem);
        self::assertSame($itemEnc, $pulledItem['enc']);
        foreach (['name', 'quantity', 'unit', 'note'] as $field) {
            self::assertArrayNotHasKey($field, $pulledItem);
        }

        // Verschluesselt gilt wieder Last-Write-Wins.
        $stale = $this->encSync($account, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [self::encListRow($listId, '2026-06-01T08:00:00.000Z', self::cipher(), $wrappedKey)]],
        ]);
        self::assertSame('stale', self::findPushResult($stale, 'lists', $listId)['reason'] ?? null);

        // Klartext von einem verschluesselten Konto ist ungueltig.
        $plain = $this->encSync($account, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [self::listRow(self::uuid(), '2026-06-02T08:00:00.000Z', 'Klartext')]],
        ]);
        self::assertSame('invalid', $plain['pushResults']['lists'][0]['reason'] ?? null);

        $this->call('POST', '/v1/me/encryption/begin', $account, ['clientId' => $migrator, 'keyCheck' => self::cipher()]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('https://spiski.app/problems/already-encrypted', $this->problemType());
    }

    public function testBeginIsIdempotentForTheSameDeviceOnly(): void
    {
        $account = self::uuid();
        $device = self::uuid();
        $keyCheck = self::cipher();

        $this->begin($account, $device, $keyCheck);
        self::assertSame(['state' => 'migrating', 'keyCheck' => $keyCheck], $this->begin($account, $device, $keyCheck));

        $this->call('POST', '/v1/me/encryption/begin', $account, ['clientId' => self::uuid(), 'keyCheck' => $keyCheck]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('https://spiski.app/problems/migration-in-progress', $this->problemType());

        $this->call('POST', '/v1/me/encryption/commit', $account, ['clientId' => self::uuid()]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('https://spiski.app/problems/migration-in-progress', $this->problemType());
    }

    public function testEmptyAccountCommitsDirectly(): void
    {
        $account = self::uuid();

        $this->call('POST', '/v1/me/encryption/commit', $account, ['clientId' => self::uuid()]);
        self::assertSame(400, $this->httpStatus(), 'Ohne begin ist keyCheck Pflicht.');

        $keyCheck = self::cipher();
        self::assertSame(
            ['state' => 'encrypted', 'keyCheck' => $keyCheck],
            $this->call('POST', '/v1/me/encryption/commit', $account, ['clientId' => self::uuid(), 'keyCheck' => $keyCheck]),
        );
    }

    public function testPlaintextAccountWithRowsCannotSkipBegin(): void
    {
        $account = self::uuid();
        $this->sync($account, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [self::listRow(self::uuid(), '2026-06-01T10:00:00.000Z')]],
        ]);

        $this->call('POST', '/v1/me/encryption/commit', $account, ['clientId' => self::uuid(), 'keyCheck' => self::cipher()]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('https://spiski.app/problems/encryption-incomplete', $this->problemType());
    }

    public function testInvalidRequestBodies(): void
    {
        $account = self::uuid();

        $this->call('POST', '/v1/me/encryption/begin', $account, ['clientId' => 'kaputt', 'keyCheck' => self::cipher()]);
        self::assertSame(400, $this->httpStatus());
        $this->call('POST', '/v1/me/encryption/begin', $account, ['clientId' => self::uuid(), 'keyCheck' => 'klartext']);
        self::assertSame(400, $this->httpStatus());
        $this->call('POST', '/v1/me/encryption/begin', $account, ['clientId' => self::uuid()]);
        self::assertSame(400, $this->httpStatus());
        $this->call('POST', '/v1/me/encryption/begin', $account, ['clientId' => self::uuid(), 'keyCheck' => 'v1.AAAAAAAAAAAAAAAA.'.str_repeat('A', 300)]);
        self::assertSame(400, $this->httpStatus(), 'keyCheck hoechstens 256 Zeichen.');

        self::assertSame('plaintext', $this->call('GET', '/v1/me/encryption', $account)['state'] ?? null);
    }

    public function testMalformedEncryptedRowsAreInvalid(): void
    {
        $account = self::uuid();
        $migrator = self::uuid();
        $this->begin($account, $migrator, self::cipher());

        $listId = self::uuid();
        $withName = self::encListRow(self::uuid(), '2026-06-01T10:00:00.000Z', self::cipher(), self::cipher()) + ['name' => 'Klartext'];
        $withoutKey = self::encListRow(self::uuid(), '2026-06-01T10:00:00.000Z', self::cipher(), null);
        $badEnc = self::encListRow(self::uuid(), '2026-06-01T10:00:00.000Z', 'v2.AAAAAAAAAAAAAAAA.AAAA', self::cipher());
        $tooLong = self::encListRow(self::uuid(), '2026-06-01T10:00:00.000Z', 'v1.AAAAAAAAAAAAAAAA.'.str_repeat('A', 6200), self::cipher());
        $good = self::encListRow($listId, '2026-06-01T10:00:00.000Z', self::cipher(), self::cipher());
        $itemWithNote = self::encItemRow(self::uuid(), $listId, '2026-06-01T10:00:00.000Z', self::cipher()) + ['note' => 'geheim'];

        $response = $this->encSync($account, [
            'since' => 0,
            'clientId' => $migrator,
            'changes' => ['lists' => [$withName, $withoutKey, $badEnc, $tooLong, $good], 'items' => [$itemWithNote]],
        ]);

        foreach ([$withName, $withoutKey, $badEnc, $tooLong] as $row) {
            self::assertSame('invalid', self::findPushResult($response, 'lists', (string) $row['id'])['reason'] ?? null);
        }
        self::assertTrue(self::findPushResult($response, 'lists', $listId)['accepted'] ?? false);
        self::assertSame('invalid', self::findPushResult($response, 'items', (string) $itemWithNote['id'])['reason'] ?? null);

        // Ein unverschluesseltes Konto darf keine verschluesselten Zeilen schreiben.
        $plain = self::uuid();
        $response = $this->encSync($plain, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [self::encListRow($id = self::uuid(), '2026-06-01T10:00:00.000Z', self::cipher(), self::cipher())]],
        ]);
        self::assertSame('invalid', self::findPushResult($response, 'lists', $id)['reason'] ?? null);
    }

    public function testBeginEndsAllSharingOfTheAccount(): void
    {
        $account = self::uuid();
        $member = self::uuid();
        $foreignOwner = self::uuid();
        $ownList = self::uuid();
        $foreignList = self::uuid();

        $this->seedList($account, $ownList, 'Eigene');
        $this->seedList($foreignOwner, $foreignList, 'Fremde');
        $this->sync($member, ['since' => 0, 'clientId' => self::uuid()]);

        $ownCode = $this->shareCode($account, $ownList);
        $this->join($member, $ownCode);
        $this->join($account, $this->shareCode($foreignOwner, $foreignList));
        $memberCursor = $this->cursor($member);
        $foreignOwnerCursor = $this->cursor($foreignOwner);

        $this->begin($account, self::uuid(), self::cipher());

        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM list_member'));
        self::assertNull($this->connection()->fetchOne('SELECT share_code FROM shopping_list WHERE id = :id', ['id' => $ownList]));

        $memberView = $this->sync($member, ['since' => $memberCursor, 'clientId' => self::uuid()]);
        self::assertSame([$ownList], $memberView['revokedLists']);
        $ownerView = $this->sync($foreignOwner, ['since' => $foreignOwnerCursor, 'clientId' => self::uuid()]);
        self::assertSame(0, self::findRow($ownerView, 'lists', $foreignList)['memberCount'] ?? null);

        // Der alte Code fuehrt nirgends mehr hin.
        $this->call('POST', '/v1/shares/'.$ownCode, $member, protocol: null);
        self::assertSame(404, $this->httpStatus());
    }

    public function testOldAppsAreLockedOutExceptForDeletion(): void
    {
        $account = self::uuid();
        $listId = self::uuid();
        $this->seedList($account, $listId, 'Liste');
        $this->begin($account, self::uuid(), self::cipher());

        $this->call('POST', '/v1/lists/'.$listId.'/share', $account, protocol: null);
        self::assertSame(426, $this->httpStatus());
        $this->call('GET', '/v1/live', $account, protocol: null);
        self::assertSame(426, $this->httpStatus());

        $this->client->request('GET', '/v1/health');
        self::assertSame(200, $this->httpStatus());

        $this->call('DELETE', '/v1/me', $account, protocol: null);
        self::assertSame(204, $this->httpStatus(), 'Recht auf Loeschung auch mit alter App.');
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM shopping_list'));
    }

    public function testEncryptedListsAreSharedWithWrappedKeys(): void
    {
        $owner = $this->encryptedAccount();
        $member = $this->encryptedAccount();
        $listId = self::uuid();
        $itemId = self::uuid();
        $ownerKey = self::cipher();
        $itemEnc = self::cipher();

        $this->encSync($owner, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => [
                'lists' => [self::encListRow($listId, '2026-06-01T10:00:00.000Z', self::cipher(), $ownerKey)],
                'items' => [self::encItemRow($itemId, $listId, '2026-06-01T10:00:01.000Z', $itemEnc)],
            ],
        ]);
        $code = $this->shareCode($owner, $listId);

        // Ein unverschluesseltes Konto kann einer verschluesselten Liste nicht beitreten.
        $plain = self::uuid();
        $this->call('POST', '/v1/shares/'.$code, $plain, ['wrappedKey' => self::cipher()]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('https://spiski.app/problems/encryption-required', $this->problemType());

        $this->call('POST', '/v1/shares/'.$code, $member);
        self::assertSame(400, $this->httpStatus(), 'Ohne wrappedKey kein Beitritt.');
        $this->call('POST', '/v1/shares/'.$code, $member, ['wrappedKey' => 'kaputt']);
        self::assertSame(400, $this->httpStatus());

        $memberKey = self::cipher();
        self::assertSame(['listId' => $listId], $this->call('POST', '/v1/shares/'.$code, $member, ['wrappedKey' => $memberKey]));

        $memberView = $this->encSync($member, ['since' => 0, 'clientId' => self::uuid()]);
        $list = self::findRow($memberView, 'lists', $listId);
        self::assertNotNull($list);
        self::assertSame('member', $list['role']);
        self::assertSame($memberKey, $list['wrappedKey'], 'Jedes Konto bekommt seinen eigenen verpackten Schluessel.');
        self::assertSame($itemEnc, self::findRow($memberView, 'items', $itemId)['enc'] ?? null);

        $ownerView = $this->encSync($owner, ['since' => 0, 'clientId' => self::uuid()]);
        self::assertSame($ownerKey, self::findRow($ownerView, 'lists', $listId)['wrappedKey'] ?? null);

        // Ein Mitglied schreibt verschluesselte Artikel in die Liste.
        $memberItem = self::uuid();
        $pushed = $this->encSync($member, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['items' => [self::encItemRow($memberItem, $listId, '2026-06-01T11:00:00.000Z', self::cipher())]],
        ]);
        self::assertTrue(self::findPushResult($pushed, 'items', $memberItem)['accepted'] ?? false);

        // Erneuter Beitritt mit neuem Schluessel ersetzt den alten.
        $newKey = self::cipher();
        $this->call('POST', '/v1/shares/'.$code, $member, ['wrappedKey' => $newKey]);
        self::assertSame(200, $this->httpStatus());
        self::assertSame($newKey, $this->connection()->fetchOne('SELECT wrapped_key FROM list_member'));
    }

    public function testPlaintextListsCannotBeJoinedByEncryptedAccounts(): void
    {
        $owner = self::uuid();
        $listId = self::uuid();
        $this->seedList($owner, $listId, 'Klartext');
        $member = $this->encryptedAccount();

        $this->call('POST', '/v1/shares/'.$this->shareCode($owner, $listId), $member, ['wrappedKey' => self::cipher()]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('https://spiski.app/problems/encryption-required', $this->problemType());
    }

    public function testDeadlineLocksOutPlaintextClients(): void
    {
        $account = self::uuid();
        $this->seedList($account, self::uuid(), 'Liste');

        $this->setDeadline('2000-01-01T00:00:00Z');

        $this->request($account, ['since' => 0, 'clientId' => self::uuid()]);
        self::assertSame(426, $this->httpStatus());

        $this->encSyncRaw($account, ['since' => 0, 'clientId' => self::uuid()]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('https://spiski.app/problems/encryption-required', $this->problemType());

        // Umstellen geht weiterhin.
        $device = self::uuid();
        $this->begin($account, $device, self::cipher());
        $this->encSync($account, ['since' => 0, 'clientId' => $device]);

        // Auch ein unverschluesseltes Konto ohne Daten bekommt mit alter App nichts mehr.
        $this->request(self::uuid(), ['since' => 0, 'clientId' => self::uuid()]);
        self::assertSame(426, $this->httpStatus());
    }

    public function testPurgeRemovesPlaintextOfUnmigratedAccountsAfterTheDeadline(): void
    {
        $plain = self::uuid();
        $plainMember = self::uuid();
        $plainList = self::uuid();
        $this->seedList($plain, $plainList, 'Klartext', withItem: true);
        $this->sync($plainMember, ['since' => 0, 'clientId' => self::uuid()]);
        $this->join($plainMember, $this->shareCode($plain, $plainList));

        $encrypted = $this->encryptedAccount();
        $encList = self::uuid();
        $this->encSync($encrypted, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [self::encListRow($encList, '2026-06-01T10:00:00.000Z', self::cipher(), self::cipher())]],
        ]);

        $migrating = self::uuid();
        $migratingList = self::uuid();
        $this->seedList($migrating, $migratingList, 'Halb umgestellt');
        $this->begin($migrating, self::uuid(), self::cipher());

        $counter = (int) $this->connection()->fetchOne('SELECT version_counter FROM app_user WHERE id = :id', ['id' => $plain]);

        // Ohne Frist: nichts.
        self::assertStringContainsString('Keine Frist', $this->runPurge());
        self::assertSame(3, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM shopping_list'));

        // Frist in der Zukunft: nichts.
        $this->setDeadline('2999-01-01T00:00:00Z');
        self::assertStringContainsString('nichts zu tun', $this->runPurge());

        $this->setDeadline('2000-01-01T00:00:00Z');
        self::assertStringContainsString('Probelauf', $this->runPurge(dryRun: true));
        self::assertSame(3, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM shopping_list'));

        $this->runPurge();

        self::assertSame(
            [$encList, $migratingList],
            array_map('strval', $this->connection()->fetchFirstColumn('SELECT id FROM shopping_list ORDER BY name = \'\' DESC, id')),
            'Nur verschluesselte und gerade umgestellte Konten behalten ihre Listen.',
        );
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM shopping_item'));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM list_member'));
        self::assertSame(0, (int) $this->connection()->fetchOne(
            "SELECT COUNT(*) FROM sync_view WHERE kind IN ('list', 'item') AND user_id IN (:a, :b)",
            ['a' => $plain, 'b' => $plainMember],
        ));
        self::assertSame($counter, (int) $this->connection()->fetchOne('SELECT version_counter FROM app_user WHERE id = :id', ['id' => $plain]), 'Der Zaehler bleibt, sonst folgte fullResync.');
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM app_user WHERE id = :id', ['id' => $plain]), 'Das Konto bleibt.');
    }

    public function testAdminShowsEncryptionWithoutContent(): void
    {
        $account = $this->encryptedAccount();
        $listId = self::uuid();
        $this->encSync($account, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => [
                'lists' => [self::encListRow($listId, '2026-06-01T10:00:00.000Z', self::cipher(), self::cipher())],
                'items' => [self::encItemRow(self::uuid(), $listId, '2026-06-01T10:00:01.000Z', self::cipher())],
            ],
        ]);
        $this->seedList(self::uuid(), self::uuid(), 'Klartextliste');

        $this->client->request('GET', '/v1/admin/lists?includeItems=1', server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::ADMIN_TOKEN]);
        self::assertSame(200, $this->httpStatus());
        /** @var array{lists: list<array<string, mixed>>} $page */
        $page = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $byId = array_column($page['lists'], null, 'id');

        self::assertTrue($byId[$listId]['encrypted']);
        self::assertNull($byId[$listId]['name']);
        self::assertSame('encrypted', $byId[$listId]['encryptionState']);
        self::assertTrue($byId[$listId]['items'][0]['encrypted']);
        self::assertNull($byId[$listId]['items'][0]['name']);
        self::assertStringNotContainsString('v1.', (string) $this->client->getResponse()->getContent(), 'Kein Chiffretext im Admin-JSON.');

        $this->client->request('GET', '/admin?open=1', server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::ADMIN_TOKEN]);
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('🔒 verschlüsselt', $html);
        self::assertStringContainsString('Klartextliste', $html);
        self::assertStringContainsString('unverschlüsselt', $html);
    }

    public function testStatusCountsAccountsPerState(): void
    {
        $this->encryptedAccount();
        $this->begin(self::uuid(), self::uuid(), self::cipher());
        $this->sync(self::uuid(), ['since' => 0, 'clientId' => self::uuid()]);

        $this->client->request('GET', '/status.json');
        /** @var array{statistics: array{encryption: array<string, int>}} $status */
        $status = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(['plaintext' => 1, 'migrating' => 1, 'encrypted' => 1], $status['statistics']['encryption']);
    }

    // --- Helfer -----------------------------------------------------------

    /** Attrappe eines Chiffretexts im Format `v1.<nonce>.<chiffretext>`. */
    private static function cipher(int $bytes = 40): string
    {
        $b64 = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        return 'v1.'.$b64(random_bytes(12)).'.'.$b64(random_bytes($bytes));
    }

    /**
     * @return array<string, mixed>
     */
    private static function encListRow(string $id, string $updatedAt, string $enc, ?string $wrappedKey): array
    {
        $row = self::listRow($id, $updatedAt);
        unset($row['name']);

        return $row + ['enc' => $enc, 'wrappedKey' => $wrappedKey];
    }

    /**
     * @return array<string, mixed>
     */
    private static function encItemRow(string $id, string $listId, string $updatedAt, string $enc): array
    {
        $row = self::itemRow($id, $listId, $updatedAt);
        unset($row['name'], $row['quantity'], $row['unit'], $row['note']);

        return $row + ['enc' => $enc];
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<mixed>
     */
    private function call(string $method, string $path, string $account, ?array $body = null, ?string $protocol = '2'): array
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$account, 'CONTENT_TYPE' => 'application/json'];
        if (null !== $protocol) {
            $server['HTTP_X_SPISKI_PROTOCOL'] = $protocol;
        }

        $this->client->request($method, $path, server: $server, content: null === $body ? '' : json_encode($body, \JSON_THROW_ON_ERROR));

        $content = (string) $this->client->getResponse()->getContent();
        if ('' === $content) {
            return [];
        }

        /** @var array<mixed> $decoded */
        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function encSync(string $account, array $payload): array
    {
        $this->encSyncRaw($account, $payload);
        self::assertSame(200, $this->httpStatus(), (string) $this->client->getResponse()->getContent());

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encSyncRaw(string $account, array $payload): void
    {
        $this->call('POST', '/v1/sync', $account, $payload);
    }

    /**
     * @return array<mixed>
     */
    private function begin(string $account, string $clientId, string $keyCheck): array
    {
        $result = $this->call('POST', '/v1/me/encryption/begin', $account, ['clientId' => $clientId, 'keyCheck' => $keyCheck]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client->getResponse()->getContent());

        return $result;
    }

    /** Ein neues Konto, direkt verschluesselt (ohne Bestand). */
    private function encryptedAccount(): string
    {
        $account = self::uuid();
        $this->call('POST', '/v1/me/encryption/commit', $account, ['clientId' => self::uuid(), 'keyCheck' => self::cipher()]);
        self::assertSame(200, $this->httpStatus());

        return $account;
    }

    private function seedList(string $account, string $listId, string $name, bool $withItem = false): void
    {
        $this->sync($account, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => [
                'lists' => [self::listRow($listId, '2026-06-01T10:00:00.000Z', $name)],
                'items' => $withItem ? [self::itemRow(self::uuid(), $listId, '2026-06-01T10:00:01.000Z', 'Milch')] : [],
            ],
        ]);
    }

    private function shareCode(string $owner, string $listId): string
    {
        $result = $this->call('POST', '/v1/lists/'.$listId.'/share', $owner);
        self::assertSame(200, $this->httpStatus(), (string) $this->client->getResponse()->getContent());
        self::assertIsString($result['code'] ?? null);

        return $result['code'];
    }

    private function join(string $account, string $code): void
    {
        $this->call('POST', '/v1/shares/'.$code, $account);
        self::assertSame(200, $this->httpStatus(), (string) $this->client->getResponse()->getContent());
    }

    private function cursor(string $account): int
    {
        $response = $this->sync($account, ['since' => 0, 'clientId' => self::uuid()]);
        self::assertIsInt($response['serverCursor']);

        return $response['serverCursor'];
    }

    private function httpStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    private function problemType(): ?string
    {
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($problem) && \is_string($problem['type'] ?? null) ? $problem['type'] : null;
    }

    private function problemDetail(): string
    {
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($problem) && \is_string($problem['detail'] ?? null) ? $problem['detail'] : '';
    }

    /**
     * Die Frist kommt aus der Umgebung; der Kernel bootet vor jedem Request neu
     * und liest sie dann frisch.
     */
    private function setDeadline(string $deadline): void
    {
        $_ENV[self::DEADLINE_ENV] = $deadline;
        $_SERVER[self::DEADLINE_ENV] = $deadline;
    }

    private function runPurge(bool $dryRun = false): string
    {
        // Frischer Kernel, damit die Frist aus setDeadline() gilt.
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $tester = new CommandTester((new Application($this->client->getKernel()))->find('app:purge-plaintext'));
        $tester->execute($dryRun ? ['--dry-run' => true] : []);
        $tester->assertCommandIsSuccessful();

        return $tester->getDisplay();
    }
}
