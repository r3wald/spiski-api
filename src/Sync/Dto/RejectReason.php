<?php

declare(strict_types=1);

namespace App\Sync\Dto;

/**
 * Gruende, aus denen eine einzelne gepushte Zeile nicht uebernommen wurde.
 * Keiner davon ist ein Request-Fehler – eine kaputte oder veraltete Zeile darf
 * den Sync eines Geraets nicht blockieren (CLAUDE.md 7).
 */
enum RejectReason: string
{
    /** Der Serverstand ist nach `(updatedAt, clientId)` neuer. */
    case Stale = 'stale';

    /** `listId` gehoert zu keiner Liste dieses Nutzers. */
    case UnknownList = 'unknown_list';

    /** Feld-Constraints aus CLAUDE.md 3 verletzt. */
    case Invalid = 'invalid';

    /** Speichergrenze des Nutzers erreicht. */
    case QuotaExceeded = 'quota_exceeded';

    /**
     * Die Liste gehoert einem anderen Konto. Mitglieder einer geteilten Liste
     * duerfen ihre Artikel aendern, die Liste selbst nur der Besitzer
     * (CLAUDE.md 12).
     */
    case Forbidden = 'forbidden';
}
