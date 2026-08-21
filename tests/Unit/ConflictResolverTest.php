<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Sync\ConflictResolver;
use App\Sync\MergeDecision;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ConflictResolverTest extends TestCase
{
    private ConflictResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ConflictResolver();
    }

    public function testUnknownRowIsInserted(): void
    {
        self::assertSame(
            MergeDecision::Insert,
            $this->resolver->decide(null, null, $this->at('10:00:00'), Uuid::v4()),
        );
    }

    public function testNewerIncomingWins(): void
    {
        self::assertSame(
            MergeDecision::Accept,
            $this->resolver->decide($this->at('10:00:00'), Uuid::v4(), $this->at('10:00:01'), Uuid::v4()),
        );
    }

    public function testOlderIncomingLoses(): void
    {
        self::assertSame(
            MergeDecision::Reject,
            $this->resolver->decide($this->at('10:00:01'), Uuid::v4(), $this->at('10:00:00'), Uuid::v4()),
        );
    }

    public function testMillisecondDifferenceIsNotATie(): void
    {
        $server = new \DateTimeImmutable('2026-01-01T10:00:00.100Z');
        $incoming = new \DateTimeImmutable('2026-01-01T10:00:00.101Z');

        self::assertSame(
            MergeDecision::Accept,
            $this->resolver->decide($server, Uuid::v4(), $incoming, Uuid::v4()),
        );
    }

    public function testTimezonesAreComparedAsInstants(): void
    {
        $server = new \DateTimeImmutable('2026-01-01T12:00:00.000+02:00');
        $incoming = new \DateTimeImmutable('2026-01-01T10:00:00.000Z');

        self::assertSame(
            MergeDecision::NoOp,
            $this->resolver->decide($server, $this->uuid('a'), $incoming, $this->uuid('a')),
            'Beide Angaben bezeichnen denselben Zeitpunkt.',
        );
    }

    public function testTieIsBrokenByHigherClientId(): void
    {
        $moment = $this->at('10:00:00');

        self::assertSame(
            MergeDecision::Accept,
            $this->resolver->decide($moment, $this->uuid('1'), $moment, $this->uuid('f')),
        );

        self::assertSame(
            MergeDecision::Reject,
            $this->resolver->decide($moment, $this->uuid('f'), $moment, $this->uuid('1')),
        );
    }

    public function testTieWithSameClientIdIsNoOp(): void
    {
        $moment = $this->at('10:00:00');
        $client = $this->uuid('7');

        self::assertSame(
            MergeDecision::NoOp,
            $this->resolver->decide($moment, $client, $moment, $client),
        );
    }

    /**
     * Die Ordnung muss total sein: fuer je zwei verschiedene Schreibvorgaenge
     * gewinnt immer derselbe, egal in welcher Reihenfolge sie ankommen.
     */
    public function testDecisionIsSymmetric(): void
    {
        $cases = [
            [$this->at('10:00:00'), $this->uuid('1'), $this->at('10:00:00'), $this->uuid('2')],
            [$this->at('10:00:00'), $this->uuid('2'), $this->at('10:00:01'), $this->uuid('1')],
            [$this->at('11:00:00'), $this->uuid('9'), $this->at('10:00:00'), $this->uuid('a')],
        ];

        foreach ($cases as [$serverTime, $serverClient, $incomingTime, $incomingClient]) {
            $forward = $this->resolver->decide($serverTime, $serverClient, $incomingTime, $incomingClient);
            $backward = $this->resolver->decide($incomingTime, $incomingClient, $serverTime, $serverClient);

            self::assertNotSame($forward, $backward);
            self::assertContains($forward, [MergeDecision::Accept, MergeDecision::Reject]);
            self::assertContains($backward, [MergeDecision::Accept, MergeDecision::Reject]);
        }
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-01-01T'.$time.'.000Z');
    }

    private function uuid(string $firstHexDigit): Uuid
    {
        return Uuid::fromString($firstHexDigit.'0000000-0000-4000-8000-000000000000');
    }
}
