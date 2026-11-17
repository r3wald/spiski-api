<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ApiProblemException;
use App\Status\StatusPageRenderer;
use App\Status\StatusReader;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Oeffentliche Statusseite: `/status.json` und `/status.html`, ohne Auth.
 *
 * Nicht in der OpenAPI-Spezifikation (die erfasst nur /v1): kein Teil des Vertrags mit
 * der App. `/v1/health` bleibt der schlanke Check fuer Container-Healthchecks.
 * Beide Antworten sind 503, wenn die Datenbank nicht erreichbar ist, damit externe
 * Monitore nur auf den Statuscode schauen muessen.
 *
 * Achtung: Unter public/ darf keine Datei status.html bzw. status.json liegen, sonst
 * liefert Caddy sie statisch aus, statt den Request an PHP zu geben.
 */
final class StatusController
{
    public function __construct(
        private readonly StatusReader $reader,
        private readonly StatusPageRenderer $renderer,
        private readonly RateLimiterFactoryInterface $statusLimiter,
        private readonly ClockInterface $clock,
    ) {}

    #[Route('/status.json', name: 'status_json', methods: ['GET'])]
    public function json(Request $request): JsonResponse
    {
        $this->throttle($request);
        $status = $this->reader->read();

        $response = new JsonResponse($status, $this->httpStatus($status['status']));
        $response->setEncodingOptions($response->getEncodingOptions() | \JSON_PRETTY_PRINT);
        // Oeffentliche Daten: externe Dashboards duerfen sie auch per fetch() lesen.
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $this->harden($response);

        return $response;
    }

    #[Route('/status.html', name: 'status_html', methods: ['GET'])]
    public function html(Request $request): Response
    {
        $this->throttle($request);
        $status = $this->reader->read();

        $response = new Response($this->renderer->render($status), $this->httpStatus($status['status']));
        $response->headers->set('Content-Type', 'text/html; charset=utf-8');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
        );
        $this->harden($response);

        return $response;
    }

    /**
     * Die Seite ist oeffentlich und fragt bei jedem Aufruf die Datenbank: das Limit pro
     * IP verhindert, dass sie sich als billiger Lastgenerator missbrauchen laesst.
     */
    private function throttle(Request $request): void
    {
        $limit = $this->statusLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            throw ApiProblemException::tooManyRequests(
                'Zu viele Status-Abfragen von dieser IP.',
                $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp(),
            );
        }
    }

    private function httpStatus(string $status): int
    {
        return 'down' === $status ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK;
    }

    private function harden(Response $response): void
    {
        // Momentaufnahme: kein Cache, sonst sieht ein Monitor einen alten Zustand.
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
    }
}
