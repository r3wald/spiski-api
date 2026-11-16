<?php

declare(strict_types=1);

namespace App\Controller;

use App\Encryption\ClientProtocol;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Sync\SyncRequestParser;
use App\Sync\SyncResponseSerializer;
use App\Sync\SyncService;
use OpenApi\Attributes as OA;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class SyncController
{
    public function __construct(
        private readonly SyncRequestParser $parser,
        private readonly SyncService $sync,
        private readonly SyncResponseSerializer $serializer,
        private readonly RateLimiterFactoryInterface $syncLimiter,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/v1/sync', name: 'v1_sync', methods: ['POST'])]
    #[OA\Post(
        summary: 'Lokale Aenderungen hochladen und fremde abholen',
        description: <<<'TEXT'
            Push und Pull in einem Request, serverseitig in einer Transaktion.

            Ablauf: eingehende Zeilen werden per Last-Write-Wins ueber
            `(updatedAt, clientId)` gegen den Serverstand gehalten, akzeptierte
            Zeilen bekommen eine neue `serverVersion`. Danach liefert die Antwort
            alle Zeilen mit `serverVersion > since` – plus den Serverstand jeder
            gepushten Zeile, auch der abgelehnten.
            TEXT,
        tags: ['Sync'],
    )]
    #[OA\Parameter(ref: '#/components/parameters/ProtocolHeader')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SyncRequest'))]
    #[OA\Response(response: 200, description: 'Merge-Ergebnis und Pull-Fenster', content: new OA\JsonContent(ref: '#/components/schemas/SyncResponse'))]
    #[OA\Response(response: 400, description: 'JSON kaputt oder Pflichtfeld fehlt', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'Bearer-Token fehlt oder ist keine UUID', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 409, description: '`migration-in-progress` (ein anderes Geraet stellt das Konto um) oder `encryption-required` (Frist abgelaufen, Konto noch unverschluesselt)', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 413, description: 'Body groesser als 1 MiB oder mehr als 500 Push-Zeilen', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 422, description: '"since" oder "limit" ausserhalb des zulaessigen Bereichs', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 426, description: 'Alte App (ohne X-Spiski-Protocol: 2) und Konto verschluesselt bzw. Frist abgelaufen', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'Rate Limit erreicht, siehe Retry-After', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function __invoke(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->enforceRateLimit($user);

        $now = $this->clock->now();
        $parsed = $this->parser->parse($request->getContent(), $now);
        $result = $this->sync->sync($user, $parsed, $now, ClientProtocol::fromRequest($request));

        return new JsonResponse($this->serializer->serialize($result));
    }

    private function enforceRateLimit(User $user): void
    {
        // Gehashter Schluessel: die Account-UUID ist das Secret und soll nicht
        // im Klartext in fremden Speichern liegen (CLAUDE.md 6).
        $limiter = $this->syncLimiter->create(hash('xxh128', $user->getUserIdentifier()));
        $limit = $limiter->consume();

        if (!$limit->isAccepted()) {
            throw ApiProblemException::tooManyRequests(
                'Sync-Limit erreicht.',
                $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp(),
            );
        }
    }
}
