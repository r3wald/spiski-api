<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Http\ProblemResponseFactory;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `Authorization: Bearer <account-uuid>` als einziger Identitaetsnachweis.
 *
 * Es gibt kein Passwort und keinen Registrierungs-Endpoint: der erste Request
 * mit einer bislang unbekannten UUID legt den Nutzer an (CLAUDE.md 6).
 */
final class UuidBearerAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly RateLimiterFactoryInterface $accountCreationLimiter,
        private readonly ProblemResponseFactory $problems,
        private readonly ClockInterface $clock,
    ) {}

    public function supports(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/v1');
    }

    public function authenticate(Request $request): Passport
    {
        $header = $request->headers->get('Authorization') ?? '';

        if (1 !== preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            throw new CustomUserMessageAuthenticationException(
                'Header "Authorization: Bearer <uuid>" fehlt oder ist unlesbar.',
            );
        }

        if (!Uuid::isValid($matches[1])) {
            throw new CustomUserMessageAuthenticationException('Bearer-Token ist keine gueltige UUID.');
        }

        $accountId = Uuid::fromString($matches[1]);
        $clientIp = $request->getClientIp() ?? 'unknown';

        return new SelfValidatingPassport(new UserBadge($accountId->toRfc4122(), fn(): User => $this->loadOrCreate(
            $accountId,
            $clientIp,
        )));
    }

    public function onAuthenticationSuccess(
        Request $request,
        #[\SensitiveParameter]
        TokenInterface $token,
        string $firewallName,
    ): ?Response {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        // Ein ueberschrittenes Limit fuer neue Accounts kommt hier als
        // eingepackte Ausnahme an und soll 429 bleiben, nicht 401 werden.
        $previous = $exception->getPrevious();
        if ($previous instanceof ApiProblemException) {
            return $this->problems->fromException($previous);
        }

        return $this->problems->fromException(ApiProblemException::unauthorized($exception->getMessageKey()));
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->problems->fromException(ApiProblemException::unauthorized('Authentifizierung erforderlich.'));
    }

    private function loadOrCreate(Uuid $accountId, string $clientIp): User
    {
        $user = $this->users->find($accountId);
        if ($user instanceof User) {
            return $user;
        }

        $limiter = $this->accountCreationLimiter->create($clientIp);
        $limit = $limiter->consume();
        if (!$limit->isAccepted()) {
            throw ApiProblemException::tooManyRequests(
                'Zu viele neue Accounts von dieser IP.',
                $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp(),
            );
        }

        // Bewusst rohes SQL statt persist()/flush(): koppeln sich zwei Geraete
        // ueber den QR-Code und synchronisieren gleichzeitig zum ersten Mal,
        // laufen beide Requests in die Anlage derselben UUID. Ein Unique-Verstoss
        // im flush() wuerde den EntityManager schliessen und den Request
        // unrettbar machen; ON CONFLICT DO NOTHING loest das Rennen still auf.
        $now = $this->clock->now();
        $this->em->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO app_user (id, version_counter, created_at, last_seen_at)
                VALUES (:id, 0, :now, :now)
                ON CONFLICT (id) DO NOTHING
                SQL,
            [
                'id' => $accountId->toRfc4122(),
                'now' => $now->format('Y-m-d H:i:s.uP'),
            ],
        );

        $user = $this->users->find($accountId);
        if (!$user instanceof User) {
            throw new CustomUserMessageAuthenticationException('Account konnte nicht angelegt werden.');
        }

        return $user;
    }
}
