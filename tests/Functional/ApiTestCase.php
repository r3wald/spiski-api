<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Basis fuer die API-Tests. Laeuft gegen eine echte PostgreSQL-Instanz; die
 * Isolation zwischen Tests erfolgt per TRUNCATE statt per umschliessender
 * Transaktion, weil der Sync selbst Transaktionen und Zeilensperren nutzt und
 * eine aeussere Transaktion die Nebenlaeufigkeitstests unbrauchbar machen wuerde.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->connection()->executeStatement('TRUNCATE app_user, shopping_list, shopping_item, list_member, sync_view RESTART IDENTITY CASCADE');
    }

    protected function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    protected function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }

    protected static function uuid(): string
    {
        return Uuid::v4()->toRfc4122();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    protected function sync(string $accountId, array $payload): array
    {
        $this->request($accountId, $payload);

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * Wie sync(), aber ohne Statuscode-Erwartung – fuer Fehlerfaelle.
     *
     * @param array<string, mixed> $payload
     */
    protected function request(string $accountId, array $payload): void
    {
        $this->client->request(
            'POST',
            '/v1/sync',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$accountId,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected static function listRow(string $id, string $updatedAt, string $name = 'Liste', ?string $deletedAt = null): array
    {
        return [
            'id' => $id,
            'name' => $name,
            // Opake Flutter-Farbe: liegt oberhalb von INT32_MAX.
            'color' => 4283215696,
            'sortOrder' => 0,
            'createdAt' => '2026-01-01T00:00:00.000Z',
            'updatedAt' => $updatedAt,
            'deletedAt' => $deletedAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function itemRow(string $id, string $listId, string $updatedAt, string $name = 'Artikel', ?string $deletedAt = null): array
    {
        return [
            'id' => $id,
            'listId' => $listId,
            'name' => $name,
            'quantity' => 1.0,
            'unit' => 'St',
            'isChecked' => false,
            'note' => null,
            'sortOrder' => 0,
            'createdAt' => '2026-01-01T00:00:00.000Z',
            'updatedAt' => $updatedAt,
            'deletedAt' => $deletedAt,
            'movedAt' => null,
        ];
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>|null
     */
    protected static function findRow(array $response, string $collection, string $id): ?array
    {
        /** @var array<string, list<array<string, mixed>>> $changes */
        $changes = $response['changes'];
        foreach ($changes[$collection] as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>|null
     */
    protected static function findPushResult(array $response, string $collection, string $id): ?array
    {
        /** @var array<string, list<array<string, mixed>>> $results */
        $results = $response['pushResults'];
        foreach ($results[$collection] as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }
}
