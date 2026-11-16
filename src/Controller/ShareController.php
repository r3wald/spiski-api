<?php

declare(strict_types=1);

namespace App\Controller;

use App\Encryption\CipherFormat;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Sharing\ShareService;
use OpenApi\Attributes as OA;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Einzelne Listen mit anderen Konten teilen (CLAUDE.md 12).
 */
final class ShareController
{
    public function __construct(
        private readonly ShareService $shares,
        private readonly RateLimiterFactoryInterface $shareJoinLimiter,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/v1/lists/{id}/share', name: 'v1_list_share', methods: ['POST'])]
    #[OA\Post(
        summary: 'Teilen-Code einer Liste abrufen',
        description: 'Liefert den Teilen-Code der Liste und legt ihn beim ersten Aufruf an. Nur der Besitzer. Der Code gilt, bis er erneuert oder das Teilen beendet wird.',
        tags: ['Sharing'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 200, description: 'Code und Link', content: new OA\JsonContent(ref: '#/components/schemas/ShareCode'))]
    #[OA\Response(response: 403, description: 'Nur der Besitzer darf teilen', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'Liste unbekannt oder nicht sichtbar', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function share(string $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        return $this->codeResponse($request, $this->shares->share($user, $this->listId($id)));
    }

    #[Route('/v1/lists/{id}/share/renew', name: 'v1_list_share_renew', methods: ['POST'])]
    #[OA\Post(
        summary: 'Neuen Teilen-Code erzeugen',
        description: 'Der bisherige Code wird ungueltig. Bestehende Mitglieder behalten ihren Zugriff. Nur der Besitzer.',
        tags: ['Sharing'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 200, description: 'Neuer Code und Link', content: new OA\JsonContent(ref: '#/components/schemas/ShareCode'))]
    #[OA\Response(response: 403, description: 'Nur der Besitzer darf teilen', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'Liste unbekannt oder nicht sichtbar', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function renew(string $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        return $this->codeResponse($request, $this->shares->renew($user, $this->listId($id)));
    }

    #[Route('/v1/lists/{id}/share', name: 'v1_list_share_stop', methods: ['DELETE'])]
    #[OA\Delete(
        summary: 'Teilen beenden',
        description: 'Der Code wird ungueltig, alle Mitglieder verlieren den Zugriff und bekommen die Liste beim naechsten Sync als `revokedLists`. Nur der Besitzer.',
        tags: ['Sharing'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 204, description: 'Teilen beendet')]
    #[OA\Response(response: 403, description: 'Nur der Besitzer darf teilen', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'Liste unbekannt oder nicht sichtbar', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function stop(string $id, #[CurrentUser] User $user): Response
    {
        $this->shares->stop($user, $this->listId($id));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/v1/lists/{id}/membership', name: 'v1_list_leave', methods: ['DELETE'])]
    #[OA\Delete(
        summary: 'Geteilte Liste verlassen',
        description: 'Das eigene Konto verliert den Zugriff; die Liste kommt beim naechsten Sync als `revokedLists`.',
        tags: ['Sharing'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 204, description: 'Verlassen')]
    #[OA\Response(response: 404, description: 'Kein Mitglied dieser Liste', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 409, description: 'Der Besitzer kann nicht austreten', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function leave(string $id, #[CurrentUser] User $user): Response
    {
        $this->shares->leave($user, $this->listId($id));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/v1/shares/{code}', name: 'v1_share_join', methods: ['POST'])]
    #[OA\Post(
        summary: 'Einer geteilten Liste beitreten',
        description: <<<'TEXT'
            Macht das eigene Konto zum Mitglied. Die Liste und ihre Artikel kommen mit dem naechsten Sync. Idempotent. Gross-/Kleinschreibung, Bindestriche und Leerzeichen im Code sind egal.

            Verschluesselte Listen: Besitzer und Mitglied muessen beide verschluesselt sein, und der Body traegt `wrappedKey` – den Listenschluessel aus dem Link-Fragment, mit dem eigenen Kontoschluessel verpackt. Ein erneuter Beitritt mit `wrappedKey` ersetzt den gespeicherten.
            TEXT,
        tags: ['Sharing'],
    )]
    #[OA\Parameter(name: 'code', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/JoinRequest'))]
    #[OA\Response(response: 200, description: 'Beigetreten', content: new OA\JsonContent(ref: '#/components/schemas/JoinResult'))]
    #[OA\Response(response: 400, description: '`wrappedKey` fehlt (verschluesselte Liste) oder ist ungueltig', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'Code unbekannt oder nicht mehr gueltig', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 409, description: 'Zu viele Mitglieder bzw. geteilte Listen, oder `encryption-required` (Besitzer und Mitglied nicht im selben Verschluesselungszustand)', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'Zu viele Versuche, siehe Retry-After', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function join(string $code, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $limit = $this->shareJoinLimiter->create(hash('xxh128', $user->getUserIdentifier()))->consume();
        if (!$limit->isAccepted()) {
            throw ApiProblemException::tooManyRequests(
                'Zu viele Beitrittsversuche.',
                $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp(),
            );
        }

        return new JsonResponse(['listId' => $this->shares->join($user, $code, $this->clock->now(), $this->wrappedKey($request))]);
    }

    /**
     * Optionaler Body `{"wrappedKey": "v1.…"}`; leerer Body wie bisher.
     */
    private function wrappedKey(Request $request): ?string
    {
        $content = trim($request->getContent());
        if ('' === $content) {
            return null;
        }

        try {
            $body = json_decode($content, true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiProblemException::badRequest('Request-Body ist kein gueltiges JSON.');
        }
        if (!\is_array($body)) {
            throw ApiProblemException::badRequest('Request-Body muss ein JSON-Objekt sein.');
        }

        $wrappedKey = $body['wrappedKey'] ?? null;
        if (null === $wrappedKey) {
            return null;
        }
        if (!CipherFormat::isShort($wrappedKey)) {
            throw ApiProblemException::badRequest('"wrappedKey" hat nicht das Format v1.<nonce>.<chiffretext>.');
        }
        \assert(\is_string($wrappedKey));

        return $wrappedKey;
    }

    private function listId(string $id): string
    {
        if (!Uuid::isValid($id)) {
            throw ApiProblemException::notFound('Liste nicht gefunden.');
        }

        return Uuid::fromString($id)->toRfc4122();
    }

    private function codeResponse(Request $request, string $code): JsonResponse
    {
        return new JsonResponse([
            'code' => $code,
            'url' => $request->getSchemeAndHttpHost().'/join/'.$code,
        ]);
    }
}
