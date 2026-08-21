<?php

declare(strict_types=1);

namespace App\Sync;

/**
 * Kappt `updatedAt`-Werte, die zu weit in der Zukunft liegen.
 *
 * Die Konfliktaufloesung ist Last-Write-Wins ueber die Client-Uhr. Ohne diese
 * Grenze gewinnt ein Geraet mit falsch gestellter Uhr dauerhaft jeden Konflikt
 * und laesst sich auch durch spaetere Aenderungen anderer Geraete nicht mehr
 * korrigieren (CLAUDE.md 5.3).
 */
final class ClockSkewGuard
{
    public function __construct(
        private readonly int $toleranceSeconds,
    ) {
    }

    public function clamp(\DateTimeImmutable $value, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $max = $now->modify(\sprintf('+%d seconds', $this->toleranceSeconds));

        return $value > $max ? $max : $value;
    }
}
