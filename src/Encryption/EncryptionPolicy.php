<?php

declare(strict_types=1);

namespace App\Encryption;

use Symfony\Component\Clock\ClockInterface;

/**
 * Die Frist fuer die Umstellung auf Verschluesselung (CLAUDE.md 14).
 *
 * `SPISKI_ENCRYPTION_DEADLINE` ist ein ISO-Datum oder leer (= keine Frist). Danach
 * werden alte Apps ganz abgewiesen, Klartext-Konten duerfen nur noch umstellen,
 * und `app:purge-plaintext` loescht ihren Klartext.
 */
final class EncryptionPolicy
{
    private readonly ?\DateTimeImmutable $deadline;

    public function __construct(
        private readonly ClockInterface $clock,
        ?string $deadline = null,
    ) {
        $deadline = trim((string) $deadline);
        if ('' === $deadline) {
            $this->deadline = null;

            return;
        }

        try {
            $this->deadline = new \DateTimeImmutable($deadline, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(\sprintf('SPISKI_ENCRYPTION_DEADLINE ist kein Datum: "%s"', $deadline), 0, $e);
        }
    }

    public function deadline(): ?\DateTimeImmutable
    {
        return $this->deadline;
    }

    public function deadlinePassed(): bool
    {
        return null !== $this->deadline && $this->clock->now() >= $this->deadline;
    }
}
