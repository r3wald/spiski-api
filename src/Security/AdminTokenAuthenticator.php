<?php

declare(strict_types=1);

namespace App\Security;

use App\Exception\ApiProblemException;
use App\Http\ProblemResponseFactory;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * `Authorization: Bearer <ADMIN_TOKEN>` fuer die Admin-Endpunkte unter /v1/admin.
 *
 * Das Token ist ein einzelnes, langes Zufalls-Secret aus der Umgebung. Ist es
 * leer oder kuerzer als MIN_TOKEN_LENGTH, ist der Admin-Zugang abgeschaltet und
 * jede Anfrage wird abgewiesen – ein leeres Token darf nie "passen". Account-UUIDs
 * der Nutzer sind hier wertlos: sie sind ein anderes Secret.
 */
final class AdminTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public const MIN_TOKEN_LENGTH = 32;

    public function __construct(
        private readonly RateLimiterFactoryInterface $adminLimiter,
        private readonly ProblemResponseFactory $problems,
        private readonly ClockInterface $clock,
        private readonly string $adminToken,
    ) {
    }

    public function supports(Request $request): bool
    {
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        // Vor dem Vergleich und fuer jede Anfrage: das Limit bremst das Raten des
        // Tokens, unabhaengig davon, ob der Versuch gelingt.
        $limit = $this->adminLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            throw ApiProblemException::tooManyRequests(
                'Zu viele Admin-Anfragen von dieser IP.',
                $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp(),
            );
        }

        $header = $request->headers->get('Authorization', '');
        if (1 !== preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            throw new CustomUserMessageAuthenticationException('Header "Authorization: Bearer <token>" fehlt oder ist unlesbar.');
        }

        // Bewusst dieselbe Meldung fuer "falsch" und "abgeschaltet": von aussen
        // soll nicht erkennbar sein, ob ein Admin-Zugang konfiguriert ist.
        if (!$this->isEnabled() || !hash_equals($this->adminToken, $matches[1])) {
            throw new CustomUserMessageAuthenticationException('Admin-Token ungueltig oder Admin-Zugang nicht aktiviert.');
        }

        return new SelfValidatingPassport(
            new UserBadge('admin', static fn (): InMemoryUser => new InMemoryUser('admin', null, ['ROLE_ADMIN'])),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $previous = $exception->getPrevious();
        if ($previous instanceof ApiProblemException) {
            return $this->problems->fromException($previous);
        }

        return $this->problems->fromException(
            ApiProblemException::unauthorized($exception->getMessageKey()),
        );
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->problems->fromException(
            ApiProblemException::unauthorized('Authentifizierung erforderlich.'),
        );
    }

    private function isEnabled(): bool
    {
        return \strlen($this->adminToken) >= self::MIN_TOKEN_LENGTH;
    }
}
