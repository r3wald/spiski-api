<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Exception\ApiProblemException;
use App\Http\ProblemResponseFactory;
use App\Security\AdminTokenAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

final class AdminTokenAuthenticatorTest extends TestCase
{
    private const TOKEN = 'a-long-random-admin-token-of-at-least-32-chars';

    public function testAcceptsTheConfiguredToken(): void
    {
        $passport = $this->authenticator(self::TOKEN)->authenticate($this->request('Bearer ' . self::TOKEN));

        $badge = $passport->getBadge(UserBadge::class);
        self::assertInstanceOf(UserBadge::class, $badge);
        self::assertSame('admin', $badge->getUserIdentifier());
    }

    public function testRejectsAWrongToken(): void
    {
        $this->expectException(CustomUserMessageAuthenticationException::class);

        $this->authenticator(self::TOKEN)->authenticate($this->request('Bearer ' . self::TOKEN . '-nope'));
    }

    public function testEmptyConfiguredTokenDisablesAdminAccess(): void
    {
        $this->expectException(CustomUserMessageAuthenticationException::class);

        // Ein leerer Konfigurationswert darf unter keinen Umstaenden "passen".
        $this->authenticator('')->authenticate($this->request('Bearer '));
    }

    public function testTooShortConfiguredTokenDisablesAdminAccessEvenWhenPresentedExactly(): void
    {
        $this->expectException(CustomUserMessageAuthenticationException::class);

        $this->authenticator('short')->authenticate($this->request('Bearer short'));
    }

    public function testMissingHeaderIsRejected(): void
    {
        $this->expectException(CustomUserMessageAuthenticationException::class);

        $this->authenticator(self::TOKEN)->authenticate(Request::create('/v1/admin/lists'));
    }

    public function testRateLimitAppliesToFailedAttemptsToo(): void
    {
        $authenticator = $this->authenticator(self::TOKEN, limit: 2);

        for ($i = 0; $i < 2; ++$i) {
            try {
                $authenticator->authenticate($this->request('Bearer falsch'));
                self::fail('Falsches Token wurde akzeptiert.');
            } catch (CustomUserMessageAuthenticationException) {
                $this->addToAssertionCount(1); // erwartet
            }
        }

        try {
            // Selbst das richtige Token wird jetzt gebremst: das Limit zaehlt Versuche, nicht Fehlschlaege.
            $authenticator->authenticate($this->request('Bearer ' . self::TOKEN));
            self::fail('Limit wurde nicht angewendet.');
        } catch (ApiProblemException $e) {
            self::assertSame(429, $e->getStatus());
            self::assertArrayHasKey('Retry-After', $e->getHeaders());
        }
    }

    private function authenticator(
        #[\SensitiveParameter]
        string $adminToken,
        int $limit = 1000,
    ): AdminTokenAuthenticator {
        $clock = new MockClock('2026-01-01 12:00:00');
        $limiter = new RateLimiterFactory([
            'id' => 'admin',
            'policy' => 'sliding_window',
            'limit' => $limit,
            'interval' => '1 minute',
        ], new InMemoryStorage());

        return new AdminTokenAuthenticator($limiter, new ProblemResponseFactory(), $clock, $adminToken);
    }

    private function request(string $authorization): Request
    {
        return Request::create('/v1/admin/lists', server: [
            'HTTP_AUTHORIZATION' => $authorization,
            'REMOTE_ADDR' => '203.0.113.9',
        ]);
    }
}
