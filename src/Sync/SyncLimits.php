<?php

declare(strict_types=1);

namespace App\Sync;

/**
 * Harte Grenzen des Sync-Protokolls (CLAUDE.md 3, 5.4 und 6).
 */
final class SyncLimits
{
    /** Maximale Anzahl Zeilen (Listen + Artikel) in einem Push. */
    public const MAX_PUSH_ROWS = 500;

    public const DEFAULT_PULL_LIMIT = 500;
    public const MAX_PULL_LIMIT = 1000;

    /** 1 MiB. */
    public const MAX_BODY_BYTES = 1048576;

    public const MAX_LISTS_PER_USER = 200;
    public const MAX_ITEMS_PER_USER = 5000;

    public const MAX_LIST_NAME_LENGTH = 100;
    public const MAX_ITEM_NAME_LENGTH = 200;
    public const MAX_UNIT_LENGTH = 20;
    public const MAX_NOTE_LENGTH = 500;

    public const INT32_MIN = -2147483648;
    public const INT32_MAX = 2147483647;

    /**
     * Farben kommen aus Flutters Color.toARGB32() und sind vorzeichenlos
     * 32 Bit – opake Farben liegen wegen des Alpha-Bytes immer oberhalb von
     * INT32_MAX. Die Spalte ist deshalb BIGINT, nicht INT.
     */
    public const UINT32_MIN = 0;
    public const UINT32_MAX = 4294967295;

    /** ISO-8601 in UTC mit Millisekunden, z. B. 2026-08-21T14:03:11.482Z. */
    public const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.v\Z';

    private function __construct()
    {
    }
}
