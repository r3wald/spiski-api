<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Live\LiveUpdates;
use OpenApi\Attributes as OA;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Zugang zu den Live-Updates (CLAUDE.md 13).
 */
final class LiveController
{
    public function __construct(
        private readonly LiveUpdates $live,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/v1/live', name: 'v1_live', methods: ['GET'])]
    #[OA\Get(
        summary: 'Live-Updates abonnieren',
        description: <<<'TEXT'
            Liefert Hub, Topic und ein Abo-Token fuer den Mercure-Hub. Der Client
            abonniert damit `GET {hub}?topic={topic}` (Server-Sent Events) und
            schickt das Token als `Authorization: Bearer` oder, im Browser, als
            Query-Parameter `authorization`.

            Jede Nachricht bedeutet nur "es gibt Neues": Der Client synchronisiert
            daraufhin wie gewohnt. `origin` ist die clientId des Geraets, das die
            Aenderung ausgeloest hat (nur auf dem eigenen Konto, sonst `null`);
            dieses Geraet kann die Nachricht ignorieren.
            TEXT,
        tags: ['Live'],
    )]
    #[OA\Response(response: 200, description: 'Abo-Daten', content: new OA\JsonContent(ref: '#/components/schemas/LiveSubscription'))]
    #[OA\Response(response: 401, description: 'Bearer-Token fehlt oder ist keine UUID', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function __invoke(#[CurrentUser] User $user): JsonResponse
    {
        $now = $this->clock->now();
        $userId = $user->getId()->toRfc4122();

        return new JsonResponse([
            // Relativ: Der Hub laeuft auf demselben Server wie die API.
            'hub' => '/.well-known/mercure',
            'topic' => $this->live->topicFor($userId),
            'token' => $this->live->subscriberToken($userId, $now),
            'expiresAt' => $now->modify('+'.LiveUpdates::TOKEN_LIFETIME.' seconds')->format(\DateTimeInterface::ATOM),
        ], headers: ['Cache-Control' => 'no-store']);
    }
}
