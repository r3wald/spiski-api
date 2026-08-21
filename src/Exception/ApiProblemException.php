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

    public static function unprocessable(string $detail): self
    {
        return new self(422, self::TYPE_BASE.'out-of-range', 'Unprocessable Content', $detail);
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
