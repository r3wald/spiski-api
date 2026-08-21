<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\ApiProblemException;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Baut Antworten nach RFC 9457 (Problem Details for HTTP APIs).
 */
final class ProblemResponseFactory
{
    /**
     * @param array<string, string> $headers
     */
    public function create(int $status, string $type, string $title, string $detail, array $headers = []): JsonResponse
    {
        $response = new JsonResponse([
            'type' => $type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ], $status);

        $response->headers->set('Content-Type', 'application/problem+json');
        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    public function fromException(ApiProblemException $exception): JsonResponse
    {
        return $this->create(
            $exception->getStatus(),
            $exception->getType(),
            $exception->getTitle(),
            $exception->getDetail(),
            $exception->getHeaders(),
        );
    }
}
