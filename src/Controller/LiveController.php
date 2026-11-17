<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Live\LiveUpdates;
use OpenApi\Attributes as OA;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Zugang zu den Live-Updates (CLAUDE.md 13).
 */
final class LiveController
{
    private const HUB_PATH = '/.well-known/mercure';
    private const COOKIE = '__Secure-mercure_access_token';

    public function __construct(
        private readonly LiveUpdates $live,
        private readonly ClockInterface $clock,
    ) {}

    #[Route('/v1/live', name: 'v1_live', methods: ['GET'])]
    #[OA\Get(summary: 'Live-Updates abonnieren', description: <<<'TEXT'
        Liefert Hub, Topic und ein Abo-Token fuer den Mercure-Hub. Der Client
        abonniert damit `GET {hub}?match={topic}` (Server-Sent Events,
        Mercure-Protokoll 1.0) und schickt das Token als
        `Authorization: Bearer`. Browser koennen bei EventSource keine Header
        setzen: Fuer sie steht das Token zusaetzlich im HttpOnly-Cookie
        `__Secure-mercure_access_token`, das nur an den Hub geht.

        Jede Nachricht bedeutet nur "es gibt Neues": Der Client synchronisiert
        daraufhin wie gewohnt. `origin` ist die clientId des Geraets, das die
        Aenderung ausgeloest hat (nur auf dem eigenen Konto, sonst `null`);
        dieses Geraet kann die Nachricht ignorieren.
        TEXT, tags: ['Live'])]
    #[OA\Response(
        response: 200,
        description: 'Abo-Daten',
        content: new OA\JsonContent(ref: '#/components/schemas/LiveSubscription'),
    )]
    #[OA\Response(
        response: 401,
        description: 'Bearer-Token fehlt oder ist keine UUID',
        content: new OA\JsonContent(ref: '#/components/schemas/Problem'),
    )]
    public function __invoke(#[CurrentUser] User $user): JsonResponse
    {
        $now = $this->clock->now();
        $userId = $user->getId()->toRfc4122();
        $token = $this->live->subscriberToken($userId, $now);
        $expiresAt = $now->modify('+' . LiveUpdates::TOKEN_LIFETIME . ' seconds');

        $response = new JsonResponse(
            [
                // Relativ: Der Hub laeuft auf demselben Server wie die API.
                'hub' => self::HUB_PATH,
                'topic' => $this->live->topicFor($userId),
                'token' => $token,
                'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
            ],
            headers: ['Cache-Control' => 'no-store'],
        );

        // Fuer EventSource im Browser (Web-App unter /app/, gleicher Server).
        // Der Name ist der Standard des Hubs; __Secure- verlangt HTTPS
        // (Browser behandeln http://localhost als sicher).
        $response->headers->setCookie(
            Cookie::create(self::COOKIE)
                ->withValue($token)
                ->withExpires($expiresAt)
                ->withPath(self::HUB_PATH)
                ->withSecure(true)
                ->withHttpOnly(true)
                ->withSameSite(Cookie::SAMESITE_STRICT),
        );

        return $response;
    }
}
