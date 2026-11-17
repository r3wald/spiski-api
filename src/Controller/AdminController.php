<?php

declare(strict_types=1);

namespace App\Controller;

use App\Admin\AdminDeleter;
use App\Admin\AdminListQuery;
use App\Admin\AdminListReader;
use App\Admin\AdminPageLimits;
use App\Exception\ApiProblemException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * JSON-Schnittstelle der Admin-Oberflaeche (Vue-App unter `/admin/`, Quellen in
 * `admin/`, statisch von Caddy ausgeliefert):
 *
 * - `GET /v1/admin/lists` – Listen aller Accounts, gefiltert, sortiert, seitenweise
 * - `GET /v1/admin/lists/{id}` – eine Liste mit allen Eintraegen
 * - `DELETE /v1/admin/lists/{id}`, `DELETE /v1/admin/items/{id}` – Tombstone wie in der App
 *
 * Alle sind nur mit `Authorization: Bearer <ADMIN_TOKEN>` erreichbar, und zwar bei
 * jedem Request – es gibt keine Session und kein Cookie. Alle sind bewusst nicht in der
 * OpenAPI-Spezifikation (siehe `areas` in nelmio_api_doc.yaml): sie sind kein Teil des
 * Vertrags mit der App.
 */
final class AdminController
{
    private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    /** Altersfilter in Tagen; 100 Jahre reichen. */
    private const MAX_AGE_DAYS = 36_500;

    public function __construct(
        private readonly AdminListReader $reader,
        private readonly AdminDeleter $deleter,
        private readonly ClockInterface $clock,
    ) {}

    #[Route('/v1/admin/lists', name: 'v1_admin_lists', methods: ['GET'])]
    public function lists(Request $request): JsonResponse
    {
        return $this->json($this->reader->read($this->listQuery($request)));
    }

    /**
     * Alle Listen-Eigentuemer (Kuerzel mit Zahl der Listen) fuer den Eigentuemer-Filter.
     */
    #[Route('/v1/admin/owners', name: 'v1_admin_owners', methods: ['GET'])]
    public function owners(): JsonResponse
    {
        return $this->json(['owners' => $this->reader->owners()]);
    }

    #[Route('/v1/admin/lists/{id}', name: 'v1_admin_list', requirements: ['id' => self::UUID], methods: ['GET'])]
    public function list(string $id): JsonResponse
    {
        $list = $this->reader->detail(strtolower($id)) ?? throw ApiProblemException::notFound('Liste nicht gefunden.');

        return $this->json($list);
    }

    /**
     * Loescht eine Liste wie der Besitzer in der App (AdminDeleter). `result` ist
     * `deleted` oder `already-deleted` (nichts geaendert).
     */
    #[Route(
        '/v1/admin/lists/{id}',
        name: 'v1_admin_list_delete',
        requirements: ['id' => self::UUID],
        methods: ['DELETE'],
    )]
    public function deleteList(Request $request, string $id): JsonResponse
    {
        $this->assertSameOrigin($request);
        $deleted = $this->deleter->deleteList(strtolower($id), $this->clock->now());

        return $this->json(['result' => $deleted ? 'deleted' : 'already-deleted']);
    }

    /**
     * Archiviert einen Eintrag wie in der App. `result` wie bei Listen.
     */
    #[Route(
        '/v1/admin/items/{id}',
        name: 'v1_admin_item_delete',
        requirements: ['id' => self::UUID],
        methods: ['DELETE'],
    )]
    public function deleteItem(Request $request, string $id): JsonResponse
    {
        $this->assertSameOrigin($request);
        $deleted = $this->deleter->deleteItem(strtolower($id), $this->clock->now());

        return $this->json(['result' => $deleted ? 'deleted' : 'already-deleted']);
    }

    /**
     * Schutz gegen CSRF: Ein HTML-Formular kann kein DELETE schicken, und ein
     * fremdes `fetch()` mit DELETE scheitert am CORS-Preflight. Zusaetzlich lehnen
     * wir Browser-Anfragen ab, die nicht von derselben Seite kommen – relevant,
     * falls eine Header-Erweiterung das Token automatisch anhaengt. curl schickt
     * den Header nicht und braucht nur das Token.
     */
    private function assertSameOrigin(Request $request): void
    {
        $site = $request->headers->get('Sec-Fetch-Site');
        if (null !== $site && 'same-origin' !== $site) {
            throw ApiProblemException::forbidden('Loeschen ist nur ueber die Admin-Oberflaeche selbst moeglich.');
        }
    }

    private function listQuery(Request $request): AdminListQuery
    {
        $status = $this->enumParameter($request, 'status', AdminListQuery::STATUSES, AdminListQuery::STATUS_ALL);
        // Aeltere Form: includeDeleted=0 blendet geloeschte Listen aus.
        if (!$request->query->has('status') && !$request->query->getBoolean('includeDeleted', true)) {
            $status = AdminListQuery::STATUS_ACTIVE;
        }

        $owner = strtolower(trim($request->query->getString('owner')));
        if ('' !== $owner && 1 !== preg_match('/^[0-9a-f]{1,12}$/', $owner)) {
            throw ApiProblemException::unprocessable(
                '"owner" muss der Anfang eines Account-Kuerzels sein (1-12 Hex-Zeichen).',
            );
        }

        $encryption = $this->enumParameter($request, 'encryption', ['', ...AdminListQuery::ENCRYPTION_STATES], '');

        $now = $this->clock->now();
        $minAge = $this->optionalIntParameter($request, 'minAgeDays', 0, self::MAX_AGE_DAYS);
        $maxAge = $this->optionalIntParameter($request, 'maxAgeDays', 0, self::MAX_AGE_DAYS);

        return new AdminListQuery(
            limit: $this->intParameter(
                $request,
                'limit',
                AdminPageLimits::DEFAULT_LIMIT,
                1,
                AdminPageLimits::MAX_LIMIT,
            ),
            offset: $this->intParameter($request, 'offset', 0, 0, AdminPageLimits::MAX_OFFSET),
            status: $status,
            query: $this->queryParameter($request),
            owner: $owner,
            encryption: '' === $encryption ? null : $encryption,
            ageField: $this->enumParameter(
                $request,
                'ageField',
                AdminListQuery::AGE_FIELDS,
                AdminListQuery::AGE_CREATED,
            ),
            olderThan: null === $minAge ? null : $now->modify(\sprintf('-%d days', $minAge)),
            newerThan: null === $maxAge ? null : $now->modify(\sprintf('-%d days', $maxAge)),
            sort: $this->enumParameter($request, 'sort', AdminListQuery::SORTS, 'updatedAt'),
            direction: $this->enumParameter($request, 'dir', AdminListQuery::DIRECTIONS, 'desc'),
            includeItems: $request->query->getBoolean('includeItems'),
            includeArchivedItems: $request->query->getBoolean('includeArchivedItems'),
        );
    }

    /**
     * Enthaelt Daten aller Nutzer: weder Browser noch Zwischenstationen sollen sie behalten
     * oder per Referer weitergeben.
     */
    private function json(mixed $data): JsonResponse
    {
        $response = new JsonResponse($data);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function queryParameter(Request $request): string
    {
        // getString() lehnt Arrays (q[]=x) selbst mit 400 ab.
        $value = trim($request->query->getString('q'));
        if (mb_strlen($value) > AdminPageLimits::MAX_QUERY_LENGTH) {
            throw ApiProblemException::unprocessable(\sprintf(
                '"q" darf hoechstens %d Zeichen lang sein.',
                AdminPageLimits::MAX_QUERY_LENGTH,
            ));
        }

        return $value;
    }

    /**
     * @param list<string> $allowed
     */
    private function enumParameter(Request $request, string $name, array $allowed, string $default): string
    {
        if (!$request->query->has($name)) {
            return $default;
        }

        $value = $request->query->getString($name);
        if (!\in_array($value, $allowed, true)) {
            throw ApiProblemException::unprocessable(\sprintf(
                '"%s" muss einer dieser Werte sein: %s.',
                $name,
                implode(', ', array_map(static fn(string $v): string => '' === $v ? '(leer)' : $v, $allowed)),
            ));
        }

        return $value;
    }

    private function optionalIntParameter(Request $request, string $name, int $min, int $max): ?int
    {
        if ('' === $request->query->getString($name)) {
            return null;
        }

        return $this->intParameter($request, $name, $min, $min, $max);
    }

    private function intParameter(Request $request, string $name, int $default, int $min, int $max): int
    {
        if (!$request->query->has($name)) {
            return $default;
        }

        $value = filter_var($request->query->get($name), \FILTER_VALIDATE_INT);
        if (false === $value || $value < $min || $value > $max) {
            throw ApiProblemException::unprocessable(\sprintf(
                '"%s" muss eine ganze Zahl zwischen %d und %d sein.',
                $name,
                $min,
                $max,
            ));
        }

        return $value;
    }
}
