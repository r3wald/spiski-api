<?php

declare(strict_types=1);

namespace App\Controller;

use App\Admin\AdminConfirmation;
use App\Admin\AdminDeleter;
use App\Admin\AdminListReader;
use App\Admin\AdminPageFilter;
use App\Admin\AdminPageLimits;
use App\Admin\AdminPageRenderer;
use App\Exception\ApiProblemException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin-Zugang: `/admin` (HTML-Seite), `/admin/items/{id}/delete` und
 * `/admin/lists/{id}/delete` (Eintrag bzw. Liste loeschen, mit Bestaetigung) und
 * `/v1/admin/lists` (JSON).
 *
 * Alle sind nur mit `Authorization: Bearer <ADMIN_TOKEN>` erreichbar, und zwar bei
 * jedem Request – es gibt kein Anmeldeformular, keine Session und kein Cookie. Alle
 * sind bewusst nicht in der OpenAPI-Spezifikation (siehe `areas` in nelmio_api_doc.yaml):
 * sie sind kein Teil des Vertrags mit der App.
 */
final class AdminController
{
    /** Die HTML-Seite zeigt kleinere Seiten als die JSON-Schnittstelle. */
    private const PAGE_DEFAULT_LIMIT = 50;

    private const DELETE_ITEM = 'delete-item';
    private const DELETE_LIST = 'delete-list';

    private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly AdminListReader $reader,
        private readonly AdminPageRenderer $renderer,
        private readonly AdminDeleter $deleter,
        private readonly AdminConfirmation $confirmation,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/admin', name: 'admin_page', methods: ['GET'])]
    public function page(Request $request): Response
    {
        [$filter, $limit, $offset] = $this->pageState($request);
        $notice = $request->query->getString('notice');

        // Die Eintraege jeder Liste gehoeren zur Seite (aufklappbar); archivierte nur auf Wunsch.
        $html = $this->renderer->render(
            $this->reader->read($limit, $offset, !$filter->hideDeletedLists, $filter->query, true, $filter->showArchivedItems),
            $filter,
            isset(AdminPageRenderer::NOTICES[$notice]) ? $notice : null,
        );

        return $this->htmlResponse($html);
    }

    /**
     * Erster Schritt beim Loeschen: zeigt den Eintrag und fragt nach. Aendert nichts.
     */
    #[Route('/admin/items/{id}/delete', name: 'admin_item_delete_confirm', requirements: ['id' => self::UUID], methods: ['GET'])]
    public function confirmDeleteItem(Request $request, string $id): Response
    {
        $id = strtolower($id);
        [$filter, $limit, $offset] = $this->pageState($request);

        $data = $this->reader->item($id) ?? throw ApiProblemException::notFound('Eintrag nicht gefunden.');

        return $this->htmlResponse($this->renderer->renderDeleteConfirmation(
            $data,
            $this->renderer->pageQuery($filter, $limit, $offset),
            $this->confirmation->issue(self::DELETE_ITEM, $id, $this->clock->now()),
        ));
    }

    /**
     * Zweiter Schritt: nur mit dem Bestaetigungstoken der Seite oben.
     */
    #[Route('/admin/items/{id}/delete', name: 'admin_item_delete', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function deleteItem(Request $request, string $id): Response
    {
        return $this->confirmedDelete(
            $request,
            strtolower($id),
            self::DELETE_ITEM,
            fn (string $id, \DateTimeImmutable $now): bool => $this->deleter->deleteItem($id, $now),
            ['deleted', 'already-deleted'],
        );
    }

    /**
     * Erster Schritt beim Loeschen einer Liste: zeigt sie mit Eintraegen und Teilnehmern
     * und fragt nach. Aendert nichts.
     */
    #[Route('/admin/lists/{id}/delete', name: 'admin_list_delete_confirm', requirements: ['id' => self::UUID], methods: ['GET'])]
    public function confirmDeleteList(Request $request, string $id): Response
    {
        $id = strtolower($id);
        [$filter, $limit, $offset] = $this->pageState($request);

        $list = $this->reader->list($id) ?? throw ApiProblemException::notFound('Liste nicht gefunden.');

        return $this->htmlResponse($this->renderer->renderListDeleteConfirmation(
            $list,
            $this->renderer->pageQuery($filter, $limit, $offset),
            $this->confirmation->issue(self::DELETE_LIST, $id, $this->clock->now()),
        ));
    }

    #[Route('/admin/lists/{id}/delete', name: 'admin_list_delete', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function deleteList(Request $request, string $id): Response
    {
        return $this->confirmedDelete(
            $request,
            strtolower($id),
            self::DELETE_LIST,
            fn (string $id, \DateTimeImmutable $now): bool => $this->deleter->deleteList($id, $now),
            ['list-deleted', 'list-already-deleted'],
        );
    }

    /**
     * Gemeinsamer zweiter Schritt: Herkunft und Bestaetigungstoken pruefen, loeschen,
     * zurueck zur Uebersicht (Post/Redirect/Get), damit ein Neuladen nichts erneut abschickt.
     *
     * @param callable(string, \DateTimeImmutable): bool $delete  true = geloescht, false = war schon geloescht
     * @param array{0: string, 1: string}                 $notices Hinweis nach dem Loeschen bzw. wenn nichts zu tun war
     */
    private function confirmedDelete(Request $request, string $id, string $action, callable $delete, array $notices): Response
    {
        [$filter, $limit, $offset] = $this->pageState($request);
        $now = $this->clock->now();

        // Zweite Schutzschicht gegen CSRF neben dem Token: Browser melden, von wo das
        // Formular kam. curl schickt den Header nicht und braucht dann nur das Token.
        $site = $request->headers->get('Sec-Fetch-Site');
        if (null !== $site && 'same-origin' !== $site) {
            throw ApiProblemException::forbidden('Loeschen ist nur ueber die Admin-Seite selbst moeglich.');
        }

        $token = $request->request->getString('confirm');
        if (!$this->confirmation->isValid($token, $action, $id, $now)) {
            throw ApiProblemException::forbidden('Bestaetigung fehlt oder ist abgelaufen. Bitte auf der Admin-Seite erneut "Loeschen" waehlen.');
        }

        $notice = $delete($id, $now) ? $notices[0] : $notices[1];

        $response = new RedirectResponse('/admin?'.$this->renderer->pageQuery($filter, $limit, $offset).'&notice='.$notice, Response::HTTP_SEE_OTHER);
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
     * Filter und Position der Admin-Seite aus dem Query-String; die Loeschseiten reichen
     * sie durch, damit es danach auf derselben Ansicht weitergeht.
     *
     * @return array{0: AdminPageFilter, 1: int, 2: int}
     */
    private function pageState(Request $request): array
    {
        return [
            new AdminPageFilter(
                query: $this->queryParameter($request),
                hideDeletedLists: $request->query->getBoolean('hideDeleted'),
                showArchivedItems: $request->query->getBoolean('archivedItems'),
                expandItems: $request->query->getBoolean('open'),
            ),
            $this->intParameter($request, 'limit', self::PAGE_DEFAULT_LIMIT, 1, AdminPageLimits::MAX_LIMIT),
            $this->intParameter($request, 'offset', 0, 0, AdminPageLimits::MAX_OFFSET),
        ];
    }

    private function htmlResponse(string $html): Response
    {
        $response = new Response($html);
        $response->headers->set('Content-Type', 'text/html; charset=utf-8');
        // Kein Skript, keine fremden Ressourcen, Formulare nur an dieselbe Seite.
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'none'; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'",
        );
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
