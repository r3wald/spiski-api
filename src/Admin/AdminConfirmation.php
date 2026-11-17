<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Bestaetigungstoken fuer zerstoererische Admin-Aktionen (Eintrag loeschen).
 *
 * Warum ueberhaupt, obwohl jede Anfrage das Admin-Token traegt: Im Browser setzt
 * eine Erweiterung wie ModHeader den Authorization-Header an **jede** Anfrage an
 * die Domain – auch an ein Formular, das eine fremde Website abschickt (CSRF).
 * Das Token steht nur auf der Bestaetigungsseite, und die kann eine fremde Seite
 * nicht lesen (Same-Origin-Policy). Ohne Session: ein HMAC ueber Aktion, Ziel und
 * Ablaufzeit, mit APP_SECRET als Schluessel.
 */
final class AdminConfirmation
{
    /** So lange darf die Bestaetigungsseite offen bleiben. */
    public const LIFETIME = 3600;

    public function __construct(
        #[\SensitiveParameter]
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {}

    public function issue(string $action, string $target, \DateTimeImmutable $now): string
    {
        $expires = $now->getTimestamp() + self::LIFETIME;

        return $expires . '.' . $this->sign($action, $target, $expires);
    }

    public function isValid(
        #[\SensitiveParameter]
        string $token,
        string $action,
        string $target,
        \DateTimeImmutable $now,
    ): bool {
        if (1 !== preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/', $token, $matches)) {
            return false;
        }

        $expires = (int) $matches[1];

        return $expires >= $now->getTimestamp() && hash_equals($this->sign($action, $target, $expires), $matches[2]);
    }

    private function sign(string $action, string $target, int $expires): string
    {
        // Eigener Zweck im Schluessel, damit sich das Token nicht mit anderen
        // HMACs ueber APP_SECRET (z. B. Mercure-Topics) verwechseln laesst.
        return hash_hmac(
            'sha256',
            $action . "\0" . strtolower($target) . "\0" . $expires,
            'admin-confirmation' . "\0" . $this->secret,
        );
    }
}
