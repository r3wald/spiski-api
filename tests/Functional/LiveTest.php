<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Live\LiveUpdates;
use App\Tests\Support\RecordingHub;
use Symfony\Component\Mercure\Update;

/**
 * Live-Updates ueber Mercure (CLAUDE.md 13): wer benachrichtigt wird, mit
 * welchem Topic, und dass die Konto-ID nie den Server verlaesst.
 */
final class LiveTest extends ApiTestCase
{
    private string $owner;
    private string $member;
    private string $listId;

    protected function setUp(): void
    {
        parent::setUp();
        RecordingHub::$updates = [];

        $this->owner = self::uuid();
        $this->member = self::uuid();
        $this->listId = self::uuid();
    }

    public function testSubscriptionContainsOnlyTheHashedTopic(): void
    {
        $this->client->request('GET', '/v1/live', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->owner]);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString($this->owner, $body);

        /** @var array{hub: string, topic: string, token: string, expiresAt: string} $live */
        $live = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('/.well-known/mercure', $live['hub']);
        self::assertMatchesRegularExpression('/^urn:spiski:account:[0-9a-f]{64}$/', $live['topic']);

        // Das Token darf genau dieses Topic abonnieren, sonst nichts.
        $claims = self::claims($live['token']);
        self::assertSame('urn:spiski:api', $claims['iss']);
        self::assertSame('urn:spiski:mercure', $claims['aud']);
        self::assertSame([[
            'type' => 'https://mercure.rocks/authorization-detail',
            'actions' => ['subscribe'],
            'topics' => [['match' => $live['topic']]],
        ]], $claims['authorization_details']);
        self::assertGreaterThan(time(), $claims['exp']);
    }

    public function testLiveRequiresAuthentication(): void
    {
        $this->client->request('GET', '/v1/live');
        self::assertResponseStatusCodeSame(401);
    }

    public function testChangeNotifiesEveryParticipantAfterCommit(): void
    {
        $device = self::uuid();
        $this->sync($this->owner, [
            'since' => 0,
            'clientId' => $device,
            'changes' => ['lists' => [self::listRow($this->listId, '2026-06-01T10:00:00.000Z')]],
        ]);
        self::assertSame([$this->topic($this->owner) => $device], $this->recorded());

        // Mitglied tritt bei: beide Konten bekommen etwas (Liste bzw. Mitgliederzahl).
        RecordingHub::$updates = [];
        $this->join($this->member, $this->share($this->owner));
        self::assertEqualsCanonicalizing(
            [$this->topic($this->owner), $this->topic($this->member)],
            array_keys($this->recorded()),
        );

        // Das Mitglied aendert einen Artikel: Der Besitzer erfaehrt es ohne
        // Herkunft, das Geraet des Mitglieds erkennt sich selbst.
        RecordingHub::$updates = [];
        $memberDevice = self::uuid();
        $this->sync($this->member, [
            'since' => 0,
            'clientId' => $memberDevice,
            'changes' => ['items' => [self::itemRow(self::uuid(), $this->listId, '2026-06-02T10:00:00.000Z', 'Brot')]],
        ]);
        self::assertSame(
            [$this->topic($this->member) => $memberDevice, $this->topic($this->owner) => null],
            $this->recorded(),
        );

        foreach (RecordingHub::$updates as $update) {
            self::assertTrue($update->isPrivate());
            self::assertStringNotContainsString($this->owner, $update->getData());
            self::assertStringNotContainsString($this->member, $update->getData());
        }
    }

    public function testPullWithoutChangesSendsNothing(): void
    {
        $this->sync($this->owner, ['since' => 0, 'clientId' => self::uuid()]);
        self::assertSame([], RecordingHub::$updates);
    }

    public function testRejectedPushSendsNothing(): void
    {
        $this->sync($this->owner, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['items' => [self::itemRow(self::uuid(), self::uuid(), '2026-06-02T10:00:00.000Z')]],
        ]);
        self::assertSame([], RecordingHub::$updates);
    }

    /**
     * @return array<string, ?string> Topic => origin
     */
    private function recorded(): array
    {
        $recorded = [];
        foreach (RecordingHub::$updates as $update) {
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

    private function share(string $account): string
    {
        $this->client->request('POST', '/v1/lists/'.$this->listId.'/share', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$account]);
        self::assertResponseIsSuccessful();

        /** @var array{code: string} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $body['code'];
    }

    private function join(string $account, string $code): void
    {
        $this->client->request('POST', '/v1/shares/'.$code, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$account]);
        self::assertResponseIsSuccessful();
    }

    /**
     * @return array<string, mixed>
     */
    private static function claims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        self::assertCount(3, $parts);

        /** @var array<string, mixed> */
        return json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true, 512, \JSON_THROW_ON_ERROR);
    }
}
