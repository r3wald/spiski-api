<?php

declare(strict_types=1);

namespace App\Sync\Dto;

use Symfony\Component\Uid\Uuid;

/**
 * Eine vom Client gepushte Artikel-Zeile, bereits geparst, validiert und mit
 * gekapptem `updatedAt` (siehe App\Sync\ClockSkewGuard).
 */
final class ItemChange
{
    public function __construct(
        public readonly Uuid $id,
        public readonly Uuid $listId,
        /** `null` bei verschluesselten Zeilen; dann stehen die Inhalte in $enc. */
        public readonly ?string $name,
        public readonly ?float $quantity,
        public readonly ?string $unit,
        public readonly bool $isChecked,
        public readonly ?string $note,
        public readonly int $sortOrder,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
        public readonly ?\DateTimeImmutable $deletedAt,
        public readonly ?\DateTimeImmutable $movedAt,
        /** Verschluesselter Inhalt: Name, Menge, Einheit, Notiz (CLAUDE.md 14). */
        public readonly ?string $enc = null,
    ) {}

    public function isEncrypted(): bool
    {
        return null !== $this->enc;
    }
}
