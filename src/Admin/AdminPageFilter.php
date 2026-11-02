<?php

declare(strict_types=1);

namespace App\Admin;

/**
 * Was die Admin-Seite anzeigt. Wird aus der Query-String gebildet und beim
 * Blaettern und im Formular unveraendert weitergereicht.
 */
final class AdminPageFilter
{
    public function __construct(
        /** Teilstring des Listennamens oder Anfang eines Account-Kuerzels; leer = alle. */
        public readonly string $query = '',
        public readonly bool $hideDeletedLists = false,
        /** Archivierte (soft-geloeschte) Eintraege mit anzeigen. */
        public readonly bool $showArchivedItems = false,
        /** Die Eintraege jeder Liste aufgeklappt darstellen. */
        public readonly bool $expandItems = false,
    ) {
    }
}
