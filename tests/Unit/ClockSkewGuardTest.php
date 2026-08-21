<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Sync\ClockSkewGuard;
use PHPUnit\Framework\TestCase;

final class ClockSkewGuardTest extends TestCase
{
    private const NOW = '2026-01-01T10:00:00.000Z';

    public function testValueInThePastIsUntouched(): void
    {
        $guard = new ClockSkewGuard(300);
        $value = new \DateTimeImmutable('2026-01-01T09:00:00.000Z');

        self::assertSame($value, $guard->clamp($value, $this->now()));
    }

    public function testValueWithinToleranceIsUntouched(): void
    {
        $guard = new ClockSkewGuard(300);
        $value = new \DateTimeImmutable('2026-01-01T10:04:00.000Z');

        self::assertSame($value, $guard->clamp($value, $this->now()));
    }

    public function testValueBeyondToleranceIsClamped(): void
    {
        $guard = new ClockSkewGuard(300);
        $value = new \DateTimeImmutable('2026-01-02T10:00:00.000Z');

        $clamped = $guard->clamp($value, $this->now());

        self::assertSame('2026-01-01T10:05:00', $clamped->format('Y-m-d\TH:i:s'));
    }

    public function testClampingIsIdempotent(): void
    {
        $guard = new ClockSkewGuard(300);
        $value = new \DateTimeImmutable('2030-01-01T00:00:00.000Z');

        $once = $guard->clamp($value, $this->now());
        $twice = $guard->clamp($once, $this->now());

        self::assertEquals($once, $twice);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }
}
