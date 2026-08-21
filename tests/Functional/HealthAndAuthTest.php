<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class HealthAndAuthTest extends ApiTestCase
{
    public function testHealthNeedsNoAuthentication(): void
    {
        $this->client->request('GET', '/v1/health');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('ok', $body['status']);
        self::assertSame('up', $body['database']);
    }

    public function testSyncWithoutTokenIsUnauthorized(): void
    {
        $this->client->request('POST', '/v1/sync', content: '{}');

        $response = $this->client->getResponse();
        self::assertSame(401, $response->getStatusCode());
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
    }

    public function testSyncWithMalformedTokenIsUnauthorized(): void
    {
        $this->client->request('POST', '/v1/sync', server: ['HTTP_AUTHORIZATION' => 'Bearer kein-uuid'], content: '{}');

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testFirstContactCreatesUserImplicitly(): void
    {
        $account = self::uuid();

        self::assertSame(0, $this->countUsers());

        $response = $this->sync($account, ['since' => 0, 'clientId' => self::uuid()]);

        self::assertSame(0, $response['serverCursor']);
        self::assertSame(1, $this->countUsers());
    }

    public function testMalformedJsonIsBadRequest(): void
    {
        $this->client->request(
            'POST',
            '/v1/sync',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::uuid()],
            content: '{nope',
        );

        $response = $this->client->getResponse();
        self::assertSame(400, $response->getStatusCode());
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
    }

    public function testMissingClientIdIsBadRequest(): void
    {
        $this->request(self::uuid(), ['since' => 0]);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testNegativeSinceIsUnprocessable(): void
    {
        $this->request(self::uuid(), ['since' => -1, 'clientId' => self::uuid()]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testLimitOutOfRangeIsUnprocessable(): void
    {
        $this->request(self::uuid(), ['since' => 0, 'clientId' => self::uuid(), 'limit' => 5000]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testAccountDeletionRemovesAllDataAndResetsCounter(): void
    {
        $account = self::uuid();
        $client = self::uuid();
        $listId = self::uuid();

        $this->sync($account, [
            'since' => 0,
            'clientId' => $client,
            'changes' => [
                'lists' => [self::listRow($listId, '2026-02-01T10:00:00.000Z')],
                'items' => [self::itemRow(self::uuid(), $listId, '2026-02-01T10:00:01.000Z')],
            ],
        ]);

        self::assertSame(1, $this->countRows('shopping_list'));
        self::assertSame(1, $this->countRows('shopping_item'));

        $this->client->request('DELETE', '/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$account]);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());

        self::assertSame(0, $this->countUsers());
        self::assertSame(0, $this->countRows('shopping_list'), 'Listen haengen per ON DELETE CASCADE am Nutzer.');
        self::assertSame(0, $this->countRows('shopping_item'));

        // Derselbe Token legt den Nutzer neu an, der Zaehler beginnt wieder bei 0.
        $fresh = $this->sync($account, ['since' => 0, 'clientId' => $client]);
        self::assertSame(0, $fresh['serverCursor']);
        self::assertSame([], $fresh['changes']['lists']);
    }

    private function countUsers(): int
    {
        return $this->countRows('app_user');
    }

    private function countRows(string $table): int
    {
        return (int) $this->connection()->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', $table));
    }
}
