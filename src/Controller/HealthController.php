<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Health-Check ohne Auth (CLAUDE.md 7).
     */
    #[Route('/v1/health', name: 'v1_health', methods: ['GET'])]
    #[OA\Get(summary: 'Health-Check', tags: ['System'], security: [])]
    #[OA\Response(response: 200, description: 'Anwendung und Datenbank erreichbar', content: new OA\JsonContent(ref: '#/components/schemas/Health'))]
    #[OA\Response(response: 503, description: 'Datenbank nicht erreichbar', content: new OA\JsonContent(ref: '#/components/schemas/Health'))]
    public function __invoke(): JsonResponse
    {
        try {
            $this->connection->executeQuery('SELECT 1');
            $databaseUp = true;
        } catch (\Throwable) {
            // Details bleiben absichtlich draussen: der Endpoint ist
            // unauthentifiziert und soll nichts ueber die Infrastruktur verraten.
            $databaseUp = false;
        }

        return new JsonResponse(
            [
                'status' => $databaseUp ? 'ok' : 'degraded',
                'database' => $databaseUp ? 'up' : 'down',
            ],
            $databaseUp ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
