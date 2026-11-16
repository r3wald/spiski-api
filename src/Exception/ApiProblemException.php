<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Fehler, der als RFC-9457-Problem-Details ausgeliefert wird (CLAUDE.md 7).
 */
final class ApiProblemException extends \RuntimeException
{
    private const TYPE_BASE = 'https://spiski.app/problems/';

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly int $status,
        private readonly string $type,
        private readonly string $title,
        string $detail,
        private readonly array $headers = [],
    ) {
        parent::__construct($detail);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDetail(): string
    {
        return $this->getMessage();
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public static function badRequest(string $detail): self
    {
        return new self(400, self::TYPE_BASE.'malformed-request', 'Bad Request', $detail);
    }

    public static function unauthorized(string $detail): self
    {
        return new self(401, self::TYPE_BASE.'invalid-token', 'Unauthorized', $detail);
    }

    public static function payloadTooLarge(string $detail): self
    {
        return new self(413, self::TYPE_BASE.'payload-too-large', 'Payload Too Large', $detail);
    }

    public static function forbidden(string $detail): self
    {
        return new self(403, self::TYPE_BASE.'forbidden', 'Forbidden', $detail);
    }

    public static function notFound(string $detail): self
    {
        return new self(404, self::TYPE_BASE.'not-found', 'Not Found', $detail);
    }

    public static function conflict(string $detail): self
    {
        return new self(409, self::TYPE_BASE.'conflict', 'Conflict', $detail);
    }

    /**
     * Ein seltenes Rennen mit einer gleichzeitigen Freigabe-Aenderung; derselbe
     * Request klappt beim naechsten Versuch (CLAUDE.md 12).
     */
    public static function retryLater(string $detail): self
    {
        return new self(503, self::TYPE_BASE.'retry-later', 'Service Unavailable', $detail, ['Retry-After' => '1']);
    }

    public static function unprocessable(string $detail): self
    {
        return new self(422, self::TYPE_BASE.'out-of-range', 'Unprocessable Content', $detail);
    }

    /**
     * Die App ist zu alt fuer dieses Konto: Es ist (oder wird gerade)
     * verschluesselt, oder die Frist fuer die Umstellung ist abgelaufen
     * (CLAUDE.md 14). Eine alte App wuerde sonst Platzhalter-Namen uebernehmen.
     */
    public static function upgradeRequired(string $detail): self
    {
        return new self(426, self::TYPE_BASE.'upgrade-required', 'Upgrade Required', $detail);
    }

    /** Ein anderes Geraet desselben Kontos stellt gerade auf Verschluesselung um. */
    public static function migrationInProgress(string $detail): self
    {
        return new self(409, self::TYPE_BASE.'migration-in-progress', 'Conflict', $detail);
    }

    /** Das Konto ist schon verschluesselt; dieses Geraet braucht den Konto-Code. */
    public static function alreadyEncrypted(string $detail): self
    {
        return new self(409, self::TYPE_BASE.'already-encrypted', 'Conflict', $detail);
    }

    /** `commit`, obwohl noch Klartext-Zeilen auf dem Server liegen. */
    public static function encryptionIncomplete(string $detail): self
    {
        return new self(409, self::TYPE_BASE.'encryption-incomplete', 'Conflict', $detail);
    }

    /**
     * Verschluesselung fehlt: beim Beitritt (Besitzer und Mitglied muessen im
     * selben Zustand sein) oder beim Sync nach Ablauf der Frist.
     */
    public static function encryptionRequired(string $detail): self
    {
        return new self(409, self::TYPE_BASE.'encryption-required', 'Conflict', $detail);
    }

    public static function tooManyRequests(string $detail, int $retryAfterSeconds): self
    {
        return new self(
            429,
            self::TYPE_BASE.'rate-limited',
            'Too Many Requests',
            $detail,
            ['Retry-After' => (string) max(1, $retryAfterSeconds)],
        );
    }
}
