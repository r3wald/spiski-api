<?php

declare(strict_types=1);

namespace App\Admin;

/**
 * Filter, Sortierung und Seitenfenster fuer `GET /v1/admin/lists`. Wird im
 * AdminController aus dem Query-String gebildet und dort validiert; der Reader
 * verlaesst sich darauf, dass nur die Werte aus den Konstanten vorkommen.
 */
final class AdminListQuery
{
    public const STATUS_ALL = 'all';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DELETED = 'deleted';
    public const STATUSES = [self::STATUS_ALL, self::STATUS_ACTIVE, self::STATUS_DELETED];

    /** Zustand des Besitzerkontos (CLAUDE.md 14). */
    public const ENCRYPTION_STATES = ['plaintext', 'migrating', 'encrypted'];

    /** Bezugsgroesse des Altersfilters: Anlage oder letzte Aenderung der Liste. */
    public const AGE_CREATED = 'created';
    public const AGE_UPDATED = 'updated';
    public const AGE_FIELDS = [self::AGE_CREATED, self::AGE_UPDATED];

    public const SORTS = ['updatedAt', 'createdAt', 'name', 'owner', 'items', 'users'];
    public const DIRECTIONS = ['asc', 'desc'];

    public function __construct(
        public readonly int $limit = AdminPageLimits::DEFAULT_LIMIT,
        public readonly int $offset = 0,
        public readonly string $status = self::STATUS_ALL,
        /** Teilstring des Listennamens oder Anfang eines Account-Kuerzels; leer = alle. */
        public readonly string $query = '',
        /** Anfang des Account-Kuerzels (nur Hex-Ziffern, kleingeschrieben); leer = alle. */
        public readonly string $owner = '',
        /** Nur Listen von Konten in diesem Zustand; null = alle. */
        public readonly ?string $encryption = null,
        public readonly string $ageField = self::AGE_CREATED,
        /** Nur Listen, deren Bezugszeitpunkt hoechstens so spaet liegt (aelter als ...). */
        public readonly ?\DateTimeImmutable $olderThan = null,
        /** Nur Listen, deren Bezugszeitpunkt mindestens so spaet liegt (juenger als ...). */
        public readonly ?\DateTimeImmutable $newerThan = null,
        public readonly string $sort = 'updatedAt',
        public readonly string $direction = 'desc',
        /** Die Eintraege jeder Liste unter `items` mitliefern. */
        public readonly bool $includeItems = false,
        /** Dabei auch archivierte (soft-geloeschte) Eintraege. */
        public readonly bool $includeArchivedItems = false,
    ) {}
}
