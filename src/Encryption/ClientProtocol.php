<?php

declare(strict_types=1);

namespace App\Encryption;

use Symfony\Component\HttpFoundation\Request;

/**
 * Was der Client kann, laut Header `X-Spiski-Protocol` (CLAUDE.md 14).
 *
 * Ein Header statt eines Body-Felds, weil Teilen, Live-Updates und Loeschen
 * keinen Body haben. Fehlt er, ist es eine App von vor der Verschluesselung.
 */
final class ClientProtocol
{
    public const HEADER = 'X-Spiski-Protocol';

    /** Apps ohne den Header. */
    public const LEGACY = 1;

    /** Ab hier kann der Client verschluesselte Zeilen lesen und schreiben. */
    public const ENCRYPTION = 2;

    private function __construct() {}

    public static function fromRequest(Request $request): int
    {
        $value = (string) $request->headers->get(self::HEADER, '');

        // Unlesbare Werte zaehlen wie ein fehlender Header: im Zweifel als
        // alte App behandeln, die keine verschluesselten Zeilen bekommt.
        return 1 === preg_match('/^\d{1,3}$/', $value) ? max(self::LEGACY, (int) $value) : self::LEGACY;
    }

    public static function supportsEncryption(Request $request): bool
    {
        return self::fromRequest($request) >= self::ENCRYPTION;
    }
}
