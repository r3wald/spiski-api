<?php

declare(strict_types=1);

namespace App\Status;

use App\Sync\SyncLimits;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Zustand der Anwendung fuer `/status.json` und `/status.html` (oeffentlich, ohne Auth).
 *
 * Bewusst nur Werte, die nichts ueber einzelne Nutzer oder die Infrastruktur verraten:
 * API-Version und Code-Stand, Erreichbarkeit der Datenbank, Stand des Schemas und
 * Summen ueber alle Konten. Keine UUIDs, keine Listennamen, keine PHP-/Datenbank-
 * Versionen, keine Fehlermeldungen.
 *
 * @phpstan-type Status array{
 *     status: 'ok'|'degraded'|'down',
 *     checkedAt: string,
 *     api: array{version: string, revision: ?string, deployedAt: ?string},
 *     database: array{status: 'up'|'down', latencyMs: ?float},
 *     migrations: array{status: 'current'|'pending'|'unknown', current: ?string, pending: ?int},
 *     statistics: ?array{
 *         accounts: array{total: int, active24h: int, active7d: int, active30d: int},
 *         lists: array{active: int, deleted: int, shared: int},
 *         items: array{active: int, archived: int},
 *         memberships: int,
 *         encryption: array{plaintext: int, migrating: int, encrypted: int}
 *     }
 * }
 */
final class StatusReader
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'doctrine.migrations.dependency_factory')]
        private readonly DependencyFactory $migrations,
        private readonly BuildInfo $buildInfo,
        private readonly ClockInterface $clock,
        #[Autowire('%spiski.api_version%')]
        private readonly string $apiVersion,
    ) {}

    /**
     * @return Status
     */
    public function read(): array
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));

        $latencyMs = $this->pingDatabase();
        $databaseUp = null !== $latencyMs;

        $migrations = $databaseUp
            ? $this->migrationState()
            : ['status' => 'unknown', 'current' => null, 'pending' => null];
        $statistics = $databaseUp ? $this->statistics($now) : null;

        $status = match (true) {
            !$databaseUp => 'down',
            'current' !== $migrations['status'] || null === $statistics => 'degraded',
            default => 'ok',
        };

        return [
            'status' => $status,
            'checkedAt' => $now->format(SyncLimits::TIMESTAMP_FORMAT),
            'api' => [
                'version' => $this->apiVersion,
                'revision' => $this->buildInfo->revision(),
                'deployedAt' => $this->buildInfo->deployedAt(),
            ],
            'database' => [
                'status' => $databaseUp ? 'up' : 'down',
                'latencyMs' => $latencyMs,
            ],
            'migrations' => $migrations,
            'statistics' => $statistics,
        ];
    }

    private function pingDatabase(): ?float
    {
        try {
            $start = hrtime(true);
            $this->connection->executeQuery('SELECT 1');

            return round((hrtime(true) - $start) / 1e6, 1);
        } catch (\Throwable) {
            // Details bleiben draussen: die Seite ist oeffentlich.
            return null;
        }
    }

    /**
     * Laeuft der Code auf dem Schema, das er erwartet? `pending` > 0 heisst: Deployment
     * ohne `make prod-migrate`.
     *
     * @return array{status: 'current'|'pending'|'unknown', current: ?string, pending: ?int}
     */
    private function migrationState(): array
    {
        try {
            $executed = $this->migrations->getMetadataStorage()->getExecutedMigrations();
            $pending = $this->migrations->getMigrationStatusCalculator()->getNewMigrations()->count();
        } catch (\Throwable) {
            return ['status' => 'unknown', 'current' => null, 'pending' => null];
        }

        // Ohne Namespace: "DoctrineMigrations\Version2026…" -> "Version2026…".
        $current = $executed->count() > 0
            ? preg_replace('/^.*\\\\/', '', (string) $executed->getLast()->getVersion())
            : null;

        return [
            'status' => 0 === $pending ? 'current' : 'pending',
            'current' => $current,
            'pending' => $pending,
        ];
    }

    /**
     * Summen ueber alle Konten. "Aktiv" heisst: mindestens ein Sync im Zeitraum
     * (`app_user.last_seen_at`).
     *
     * @return array{
     *     accounts: array{total: int, active24h: int, active7d: int, active30d: int},
     *     lists: array{active: int, deleted: int, shared: int},
     *     items: array{active: int, archived: int},
     *     memberships: int,
     *     encryption: array{plaintext: int, migrating: int, encrypted: int}
     * }|null
     */
    private function statistics(\DateTimeImmutable $now): ?array
    {
        $format = 'Y-m-d H:i:s.vP';

        try {
            /** @var array<string, int|string>|false $row */
            $row = $this->connection->fetchAssociative(
                <<<'SQL'
                    SELECT
                        (SELECT COUNT(*) FROM app_user) AS accounts,
                        (SELECT COUNT(*) FROM app_user WHERE last_seen_at >= :day) AS active_24h,
                        (SELECT COUNT(*) FROM app_user WHERE last_seen_at >= :week) AS active_7d,
                        (SELECT COUNT(*) FROM app_user WHERE last_seen_at >= :month) AS active_30d,
                        (SELECT COUNT(*) FROM shopping_list WHERE deleted_at IS NULL) AS lists_active,
                        (SELECT COUNT(*) FROM shopping_list WHERE deleted_at IS NOT NULL) AS lists_deleted,
                        (SELECT COUNT(DISTINCT m.list_id) FROM list_member m
                            JOIN shopping_list l ON l.id = m.list_id
                            WHERE l.deleted_at IS NULL) AS lists_shared,
                        (SELECT COUNT(*) FROM shopping_item WHERE deleted_at IS NULL) AS items_active,
                        (SELECT COUNT(*) FROM shopping_item WHERE deleted_at IS NOT NULL) AS items_archived,
                        (SELECT COUNT(*) FROM list_member) AS memberships,
                        (SELECT COUNT(*) FROM app_user WHERE encryption_state = 'plaintext') AS enc_plaintext,
                        (SELECT COUNT(*) FROM app_user WHERE encryption_state = 'migrating') AS enc_migrating,
                        (SELECT COUNT(*) FROM app_user WHERE encryption_state = 'encrypted') AS enc_encrypted
                    SQL,
                [
                    'day' => $now->modify('-1 day')->format($format),
                    'week' => $now->modify('-7 days')->format($format),
                    'month' => $now->modify('-30 days')->format($format),
                ],
            );
        } catch (\Throwable) {
            return null;
        }

        if (false === $row) {
            return null;
        }

        return [
            'accounts' => [
                'total' => (int) $row['accounts'],
                'active24h' => (int) $row['active_24h'],
                'active7d' => (int) $row['active_7d'],
                'active30d' => (int) $row['active_30d'],
            ],
            'lists' => [
                'active' => (int) $row['lists_active'],
                'deleted' => (int) $row['lists_deleted'],
                'shared' => (int) $row['lists_shared'],
            ],
            'items' => [
                'active' => (int) $row['items_active'],
                'archived' => (int) $row['items_archived'],
            ],
            'memberships' => (int) $row['memberships'],
            // Konten je Zustand der Umstellung auf Verschluesselung (CLAUDE.md 14).
            'encryption' => [
                'plaintext' => (int) $row['enc_plaintext'],
                'migrating' => (int) $row['enc_migrating'],
                'encrypted' => (int) $row['enc_encrypted'],
            ],
        ];
    }
}
