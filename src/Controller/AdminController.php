<?php

declare(strict_types=1);

namespace App\Controller;

use App\Admin\AdminListReader;
use App\Admin\AdminPageFilter;
use App\Admin\AdminPageLimits;
use App\Admin\AdminPageRenderer;
use App\Exception\ApiProblemException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin-Zugang: `/admin` (HTML-Seite) und `/v1/admin/lists` (JSON).
 *
 * Beide sind nur mit `Authorization: Bearer <ADMIN_TOKEN>` erreichbar, und zwar bei
 * jedem Request – es gibt kein Formular, keine Session und kein Cookie. Beide sind
 * bewusst nicht in der OpenAPI-Spezifikation (siehe `areas` in nelmio_api_doc.yaml):
 * sie sind kein Teil des Vertrags mit der App.
 */
final class AdminController
{
    /** Die HTML-Seite zeigt kleinere Seiten als die JSON-Schnittstelle. */
    private const PAGE_DEFAULT_LIMIT = 50;

    public function __construct(
        private readonly AdminListReader $reader,
        private readonly AdminPageRenderer $renderer,
    ) {
    }

    #[Route('/admin', name: 'admin_page', methods: ['GET'])]
    public function page(Request $request): Response
    {
        $limit = $this->intParameter($request, 'limit', self::PAGE_DEFAULT_LIMIT, 1, AdminPageLimits::MAX_LIMIT);
        $offset = $this->intParameter($request, 'offset', 0, 0, AdminPageLimits::MAX_OFFSET);
        $filter = new AdminPageFilter(
            query: $this->queryParameter($request),
            hideDeletedLists: $request->query->getBoolean('hideDeleted'),
            showArchivedItems: $request->query->getBoolean('archivedItems'),
            expandItems: $request->query->getBoolean('open'),
        );

        // Die Eintraege jeder Liste gehoeren zur Seite (aufklappbar); archivierte nur auf Wunsch.
        $html = $this->renderer->render(
            $this->reader->read($limit, $offset, !$filter->hideDeletedLists, $filter->query, true, $filter->showArchivedItems),
            $filter,
        );

        $response = new Response($html);
        $response->headers->set('Content-Type', 'text/html; charset=utf-8');
        // Kein Skript, keine fremden Ressourcen, nur Formular an dieselbe Seite.
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'none'; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'",
        );
        $this->hardenResponse($response);

        return $response;
    }

    #[Route('/v1/admin/lists', name: 'v1_admin_lists', methods: ['GET'])]
    public function lists(Request $request): JsonResponse
    {
        $limit = $this->intParameter($request, 'limit', AdminPageLimits::DEFAULT_LIMIT, 1, AdminPageLimits::MAX_LIMIT);
        $offset = $this->intParameter($request, 'offset', 0, 0, AdminPageLimits::MAX_OFFSET);
        $includeDeleted = $request->query->getBoolean('includeDeleted', true);

        $response = new JsonResponse($this->reader->read(
            $limit,
            $offset,
            $includeDeleted,
            $this->queryParameter($request),
            $request->query->getBoolean('includeItems'),
            $request->query->getBoolean('includeArchivedItems'),
        ));
        $this->hardenResponse($response);

        return $response;
    }

    /**
     * Enthaelt Daten aller Nutzer: weder Browser noch Zwischenstationen sollen sie behalten
     * oder per Referer weitergeben.
     */
    private function hardenResponse(Response $response): void
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
    }

    private function queryParameter(Request $request): string
    {
        // getString() lehnt Arrays (q[]=x) selbst mit 400 ab.
        $value = trim($request->query->getString('q'));
        if (mb_strlen($value) > AdminPageLimits::MAX_QUERY_LENGTH) {
            throw ApiProblemException::unprocessable(\sprintf('"q" darf hoechstens %d Zeichen lang sein.', AdminPageLimits::MAX_QUERY_LENGTH));
        }

        return $value;
    }

    private function intParameter(Request $request, string $name, int $default, int $min, int $max): int
    {
        if (!$request->query->has($name)) {
            return $default;
        }

        $value = filter_var($request->query->get($name), \FILTER_VALIDATE_INT);
        if (false === $value || $value < $min || $value > $max) {
            throw ApiProblemException::unprocessable(\sprintf('"%s" muss eine ganze Zahl zwischen %d und %d sein.', $name, $min, $max));
        }

        return $value;
    }
}
