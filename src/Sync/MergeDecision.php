<?php

declare(strict_types=1);

namespace App\Sync;

enum MergeDecision
{
    /** Zeile ist serverseitig unbekannt. */
    case Insert;

    /** Eingehende Zeile gewinnt. */
    case Accept;

    /** Serverstand gewinnt. */
    case Reject;

    /**
     * Identischer Schreibvorgang desselben Geraets. Wird als erfolgreich
     * gemeldet, aber nicht geschrieben – sonst wuerde jeder Retry nach einem
     * Netzwerk-Timeout den serverVersion-Zaehler weitertreiben und allen
     * anderen Geraeten eine Aenderung vortaeuschen.
     */
    case NoOp;
}
