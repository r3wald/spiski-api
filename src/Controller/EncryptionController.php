<?php

declare(strict_types=1);

namespace App\Controller;

use App\Encryption\CipherFormat;
use App\Encryption\ClientProtocol;
use App\Encryption\EncryptionService;
use App\Entity\User;
use App\Exception\ApiProblemException;
use OpenApi\Attributes as OA;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Umstellung eines Kontos auf Ende-zu-Ende-Verschluesselung (CLAUDE.md 14).
 * Nur fuer Clients mit Protokoll 2 (Header `X-Spiski-Protocol: 2`).
 */
final class EncryptionController
{
    public function __construct(
        private readonly EncryptionService $encryption,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/v1/me/encryption', name: 'v1_me_encryption', methods: ['GET'])]
    #[OA\Get(
        summary: 'Verschluesselungszustand des Kontos',
        description: 'Ein Geraet entscheidet damit zwischen „umstellen", „Umstellung fortsetzen" und „Konto-Code anfordern". Mit `keyCheck` prueft es vor dem ersten Sync, ob sein Kontoschluessel passt.',
        tags: ['Encryption'],
    )]
    #[OA\Parameter(ref: '#/components/parameters/ProtocolHeader')]
    #[OA\Response(response: 200, description: 'Zustand', content: new OA\JsonContent(ref: '#/components/schemas/EncryptionStatus'))]
    #[OA\Response(response: 426, description: 'Header X-Spiski-Protocol fehlt (alte App)', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function status(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->requireProtocol($request);

        return new JsonResponse($this->encryption->status($user));
    }

    #[Route('/v1/me/encryption/begin', name: 'v1_me_encryption_begin', methods: ['POST'])]
    #[OA\Post(
        summary: 'Umstellung auf Verschluesselung beginnen',
        description: <<<'TEXT'
            Setzt das Konto auf `migrating`. Vorher werden **alle** Teilen-Beziehungen
            geloest: Mitglieder eigener Listen verlieren den Zugriff (`revokedLists`),
            Teilen-Codes werden ungueltig, aus fremden Listen tritt das Konto aus.
            Danach darf nur noch dieses Geraet synchronisieren; alte Apps bekommen `426`.
            Fuer dasselbe Geraet idempotent.
            TEXT,
        tags: ['Encryption'],
    )]
    #[OA\Parameter(ref: '#/components/parameters/ProtocolHeader')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/EncryptionBegin'))]
    #[OA\Response(response: 200, description: 'Umstellung laeuft', content: new OA\JsonContent(ref: '#/components/schemas/EncryptionStatus'))]
    #[OA\Response(response: 400, description: 'clientId oder keyCheck fehlt oder ist ungueltig', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 409, description: '`migration-in-progress` (anderes Geraet) oder `already-encrypted`', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 426, description: 'Header X-Spiski-Protocol fehlt (alte App)', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function begin(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->requireProtocol($request);
        $body = $this->body($request);
        $keyCheck = $this->keyCheck($body, required: true);
        \assert(null !== $keyCheck);

        return new JsonResponse($this->encryption->begin($user, $this->clientId($body), $keyCheck, $this->clock->now()));
    }

    #[Route('/v1/me/encryption/commit', name: 'v1_me_encryption_commit', methods: ['POST'])]
    #[OA\Post(
        summary: 'Umstellung auf Verschluesselung abschliessen',
        description: <<<'TEXT'
            Setzt das Konto auf `encrypted`, wenn keine Klartext-Zeile mehr auf dem
            Server liegt (sonst `409 encryption-incomplete`). Ein Konto ohne Zeilen
            darf ohne `begin` direkt hierher und liefert dann `keyCheck` mit.
            TEXT,
        tags: ['Encryption'],
    )]
    #[OA\Parameter(ref: '#/components/parameters/ProtocolHeader')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/EncryptionCommit'))]
    #[OA\Response(response: 200, description: 'Konto ist verschluesselt', content: new OA\JsonContent(ref: '#/components/schemas/EncryptionStatus'))]
    #[OA\Response(response: 400, description: 'clientId fehlt oder keyCheck fehlt bzw. ist ungueltig', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 409, description: '`encryption-incomplete` (Klartext-Zeilen vorhanden) oder `migration-in-progress`', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 426, description: 'Header X-Spiski-Protocol fehlt (alte App)', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function commit(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->requireProtocol($request);
        $body = $this->body($request);

        return new JsonResponse($this->encryption->commit($user, $this->clientId($body), $this->keyCheck($body, required: false), $this->clock->now()));
    }

    private function requireProtocol(Request $request): void
    {
        if (!ClientProtocol::supportsEncryption($request)) {
            throw ApiProblemException::upgradeRequired('Verschluesselung braucht eine neuere App-Version.');
        }
    }

    /**
     * @return array<mixed>
     */
    private function body(Request $request): array
    {
        try {
            $body = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiProblemException::badRequest('Request-Body ist kein gueltiges JSON.');
        }
        if (!\is_array($body)) {
            throw ApiProblemException::badRequest('Request-Body muss ein JSON-Objekt sein.');
        }

        return $body;
    }

    /**
     * @param array<mixed> $body
     */
    private function clientId(array $body): Uuid
    {
        $value = $body['clientId'] ?? null;
        if (!\is_string($value) || !Uuid::isValid($value)) {
            throw ApiProblemException::badRequest('"clientId" fehlt oder ist keine gueltige UUID.');
        }

        return Uuid::fromString($value);
    }

    /**
     * @param array<mixed> $body
     */
    private function keyCheck(array $body, bool $required): ?string
    {
        $value = $body['keyCheck'] ?? null;
        if (null === $value && !$required) {
            return null;
        }
        if (!CipherFormat::isShort($value)) {
            throw ApiProblemException::badRequest('"keyCheck" fehlt oder hat nicht das Format v1.<nonce>.<chiffretext>.');
        }
        \assert(\is_string($value));

        return $value;
    }
}
