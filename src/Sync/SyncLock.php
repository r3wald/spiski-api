<?php

declare(strict_types=1);

namespace App\Sync;

use Doctrine\DBAL\Connection;

/**
 * Transaktionsweite Advisory-Sperre vor allen Zeilensperren (CLAUDE.md 12).
 *
 * Eine Aenderung an einer geteilten Liste vergibt Versionen in mehreren
 * Konten und sperrt dafuer mehrere app_user-Zeilen. Damit sich zwei solche
 * Transaktionen nicht gegenseitig blockieren, laufen sie nacheinander
 * (exklusiv). Alles andere – der Normalfall, ein Konto mit eigenen Listen –
 * nimmt die Sperre geteilt und laeuft weiter parallel. Weil jede Transaktion
 * die Advisory-Sperre zuerst nimmt und eine geteilte Transaktion nur ihre
 * eigene Kontozeile sperrt, kann kein Zyklus entstehen.
 *
 * Mitgliedschaften aendern sich nur unter der exklusiven Sperre. Wer die
 * geteilte Sperre haelt, sieht sie also stabil.
 */
final class SyncLock
{
    /** Beliebiger, projektweit fester Schluessel ("SPIS"). */
    private const KEY = 0x53504953;

    public function shared(Connection $connection): void
    {
        $connection->executeQuery('SELECT pg_advisory_xact_lock_shared(:key)', ['key' => self::KEY]);
    }

    public function exclusive(Connection $connection): void
    {
        $connection->executeQuery('SELECT pg_advisory_xact_lock(:key)', ['key' => self::KEY]);
    }
}
