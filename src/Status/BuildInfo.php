<?php

declare(strict_types=1);

namespace App\Status;

use App\Sync\SyncLimits;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Welcher Code-Stand laeuft: Git-Revision und Zeitpunkt des Deployments.
 *
 * Beides schreiben `update-prod.sh` bzw. `update-staging.sh` beim Deployment nach `build-info.json` im Projektverzeichnis
 * (nicht im Repo, nicht unter public/). Lokal fehlt die Datei, dann sind beide Werte null.
 * Der Inhalt wird streng geprueft, weil er unauthentifiziert ausgeliefert wird.
 */
final class BuildInfo
{
    public const FILE = 'build-info.json';

    /** @var array{revision: ?string, deployedAt: ?string}|null */
    private ?array $data = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /** Kurzer Commit-Hash, mit `-dirty`, wenn beim Deployment uncommittete Aenderungen dabei waren. */
    public function revision(): ?string
    {
        return $this->load()['revision'];
    }

    /** ISO-8601 in UTC mit Millisekunden. */
    public function deployedAt(): ?string
    {
        return $this->load()['deployedAt'];
    }

    /**
     * @return array{revision: ?string, deployedAt: ?string}
     */
    private function load(): array
    {
        if (null !== $this->data) {
            return $this->data;
        }

        $this->data = ['revision' => null, 'deployedAt' => null];

        $path = $this->projectDir.'/'.self::FILE;
        $raw = is_file($path) ? @file_get_contents($path) : false;
        if (false === $raw) {
            return $this->data;
        }

        try {
            $decoded = json_decode($raw, true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->data;
        }
        if (!\is_array($decoded)) {
            return $this->data;
        }

        $revision = $decoded['revision'] ?? null;
        if (\is_string($revision) && 1 === preg_match('/^[0-9a-f]{7,40}(-dirty)?$/', $revision)) {
            $this->data['revision'] = $revision;
        }

        $deployedAt = $decoded['deployedAt'] ?? null;
        if (\is_string($deployedAt) && '' !== $deployedAt) {
            try {
                $this->data['deployedAt'] = (new \DateTimeImmutable($deployedAt))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format(SyncLimits::TIMESTAMP_FORMAT);
            } catch (\Exception) {
                // Unlesbar: lieber kein Wert als ein falscher.
            }
        }

        return $this->data;
    }
}
