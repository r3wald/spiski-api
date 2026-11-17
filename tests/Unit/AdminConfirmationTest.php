<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Admin\AdminConfirmation;
use PHPUnit\Framework\TestCase;

final class AdminConfirmationTest extends TestCase
{
    private const ITEM = '3f0c8a52-6d0e-4a8b-9a43-2f1d5c7e9b10';

    private AdminConfirmation $confirmation;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->confirmation = new AdminConfirmation('test-secret');
        $this->now = new \DateTimeImmutable('2026-11-16 12:00:00');
    }

    public function testTokenIsValidForItsActionAndTarget(): void
    {
        $token = $this->confirmation->issue('delete-item', self::ITEM, $this->now);

        self::assertTrue($this->confirmation->isValid($token, 'delete-item', self::ITEM, $this->now));
        self::assertTrue($this->confirmation->isValid($token, 'delete-item', strtoupper(self::ITEM), $this->now));
    }

    public function testTokenIsBoundToTargetActionAndSecret(): void
    {
        $token = $this->confirmation->issue('delete-item', self::ITEM, $this->now);

        self::assertFalse($this->confirmation->isValid(
            $token,
            'delete-item',
            '00000000-0000-4000-8000-000000000000',
            $this->now,
        ));
        self::assertFalse($this->confirmation->isValid($token, 'delete-list', self::ITEM, $this->now));
        self::assertFalse(new AdminConfirmation('other-secret')->isValid(
            $token,
            'delete-item',
            self::ITEM,
            $this->now,
        ));
    }

    public function testTokenExpires(): void
    {
        $token = $this->confirmation->issue('delete-item', self::ITEM, $this->now);

        self::assertTrue($this->confirmation->isValid(
            $token,
            'delete-item',
            self::ITEM,
            $this->now->modify('+' . AdminConfirmation::LIFETIME . ' seconds'),
        ));
        self::assertFalse($this->confirmation->isValid(
            $token,
            'delete-item',
            self::ITEM,
            $this->now->modify('+' . (AdminConfirmation::LIFETIME + 1) . ' seconds'),
        ));
    }

    public function testExtendingTheExpiryBreaksTheSignature(): void
    {
        $token = $this->confirmation->issue('delete-item', self::ITEM, $this->now);
        [, $signature] = explode('.', $token);

        self::assertFalse($this->confirmation->isValid(
            '9999999999.' . $signature,
            'delete-item',
            self::ITEM,
            $this->now,
        ));
    }

    public function testMalformedTokensAreRejected(): void
    {
        foreach (['', 'abc', '123.', '.' . str_repeat('a', 64), '123.' . str_repeat('g', 64)] as $token) {
            self::assertFalse($this->confirmation->isValid($token, 'delete-item', self::ITEM, $this->now), $token);
        }
    }
}
