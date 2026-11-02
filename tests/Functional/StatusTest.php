<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class StatusTest extends ApiTestCase
{
    public function testJsonIsPublicAndReportsAHealthyApplication(): void
    {
        $this->client->request('GET', '/status.json');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $body = $this->statusJson();
        self::assertSame('ok', $body['status']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $body['checkedAt']);
        self::assertSame('up', $body['database']['status']);
        self::assertIsFloat($body['database']['latencyMs']);
        self::assertSame('current', $body['migrations']['status']);
        self::assertSame(0, $body['migrations']['pending']);
        self::assertMatchesRegularExpression('/^Version\d{14}$/', $body['migrations']['current']);
    }

    public function testApiVersionMatchesTheOpenApiSpecification(): void
    {
        $spec = (string) file_get_contents(\dirname(__DIR__, 2).'/openapi.yaml');
        self::assertSame(1, preg_match('/^  version: \'?([^\s\']+)\'?$/m', $spec, $matches));

        self::assertSame($matches[1], $this->statusJson()['api']['version']);
    }

    public function testStatisticsCountAllAccountsListsAndItems(): void
    {
        $owner = self::uuid();
        $member = self::uuid();
        $listId = self::uuid();

        $this->sync($owner, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => [
                'lists' => [
                    self::listRow($listId, '2026-02-01T10:00:00.000Z'),
                    self::listRow(self::uuid(), '2026-02-01T10:00:00.000Z', 'Weg', '2026-02-01T10:00:00.000Z'),
                ],
                'items' => [
                    self::itemRow(self::uuid(), $listId, '2026-02-01T10:00:01.000Z'),
                    self::itemRow(self::uuid(), $listId, '2026-02-01T10:00:01.000Z'),
                    self::itemRow(self::uuid(), $listId, '2026-02-01T10:00:01.000Z', 'Alt', '2026-02-01T10:00:02.000Z'),
                ],
            ],
        ]);
        $this->sync($member, ['since' => 0, 'clientId' => self::uuid()]);

        $this->client->request('POST', '/v1/lists/'.$listId.'/share', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$owner]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{code: string} $share */
        $share = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->client->request('POST', '/v1/shares/'.$share['code'], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$member]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // Ein seit 10 Tagen inaktives Konto: zaehlt fuer 30 Tage, nicht fuer 24 h und 7 Tage.
        $this->sync($idle = self::uuid(), ['since' => 0, 'clientId' => self::uuid()]);
        $this->connection()->executeStatement(
            "UPDATE app_user SET last_seen_at = now() - interval '10 days' WHERE id = :id",
            ['id' => $idle],
        );

        self::assertSame([
            'accounts' => ['total' => 3, 'active24h' => 2, 'active7d' => 2, 'active30d' => 3],
            'lists' => ['active' => 1, 'deleted' => 1, 'shared' => 1],
            'items' => ['active' => 2, 'archived' => 1],
            'memberships' => 1,
        ], $this->statusJson()['statistics']);
    }

    public function testEmptyDatabaseHasZeroStatistics(): void
    {
        self::assertSame([
            'accounts' => ['total' => 0, 'active24h' => 0, 'active7d' => 0, 'active30d' => 0],
            'lists' => ['active' => 0, 'deleted' => 0, 'shared' => 0],
            'items' => ['active' => 0, 'archived' => 0],
            'memberships' => 0,
        ], $this->statusJson()['statistics']);
    }

    public function testHtmlIsPublicAndShowsTheState(): void
    {
        $this->client->request('GET', '/status.html');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));

        $html = (string) $response->getContent();
        self::assertStringContainsString('Alle Systeme betriebsbereit', $html);
        self::assertStringContainsString('<code>'.$this->statusJson()['api']['version'].'</code>', $html);
        self::assertStringContainsString('href="/status.json"', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function testNeitherPageRevealsAccountsOrUserContent(): void
    {
        $account = self::uuid();
        $listId = self::uuid();
        $this->sync($account, [
            'since' => 0,
            'clientId' => self::uuid(),
            'changes' => ['lists' => [self::listRow($listId, '2026-02-01T10:00:00.000Z', 'Geheime Liste')]],
        ]);

        foreach (['/status.json', '/status.html'] as $path) {
            $this->client->request('GET', $path);
            $content = (string) $this->client->getResponse()->getContent();

            self::assertStringNotContainsString($account, $content, $path);
            self::assertStringNotContainsString($listId, $content, $path);
            self::assertStringNotContainsString('Geheime Liste', $content, $path);
        }
    }

    public function testAccountTokenIsIgnoredAndCreatesNoAccount(): void
    {
        $this->client->request('GET', '/status.json', server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::uuid()]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM app_user'));
    }

    public function testOnlyGetIsAllowed(): void
    {
        $this->client->request('POST', '/status.json');

        self::assertSame(405, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @return array<string, mixed>
     */
    private function statusJson(): array
    {
        $this->client->request('GET', '/status.json');

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $body;
    }
}
