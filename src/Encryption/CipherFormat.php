<?php

declare(strict_types=1);

namespace App\Encryption;

use App\Sync\SyncLimits;

/**
 * Formatpruefung fuer Chiffretexte (CLAUDE.md 14). Mehr kann der Server nicht
 * pruefen: Er hat keinen Schluessel und sieht nie den Inhalt.
 */
final class CipherFormat
{
    private function __construct() {}

    /** `enc` einer Zeile: Name bzw. Name, Menge, Einheit, Notiz. */
    public static function isEnc(mixed $value): bool
    {
        return self::matches($value, SyncLimits::MAX_ENC_LENGTH);
    }

    /** `wrappedKey` (verpackter Listenschluessel) und `keyCheck`. */
    public static function isShort(mixed $value): bool
    {
        return self::matches($value, SyncLimits::MAX_WRAPPED_KEY_LENGTH);
    }

    private static function matches(mixed $value, int $maxLength): bool
    {
        return \is_string($value) && \strlen($value) <= $maxLength && 1 === preg_match(SyncLimits::ENC_PATTERN, $value);
    }
}
