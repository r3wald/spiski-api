<?php

declare(strict_types=1);

namespace App\Admin;

/**
 * Grenzen der Admin-Endpunkte an einer Stelle, damit Controller und Seite
 * dieselben Werte benutzen.
 */
final class AdminPageLimits
{
    public const DEFAULT_LIMIT = 100;
    public const MAX_LIMIT = 500;
    public const MAX_OFFSET = 1_000_000;
    public const MAX_QUERY_LENGTH = 100;

    private function __construct()
    {
    }
}
