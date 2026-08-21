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
        public readonly string $name,
        public readonly ?float $quantity,
        public readonly ?string $unit,
        public readonly bool $isChecked,
        public readonly ?string $note,
        public readonly int $sortOrder,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
        public readonly ?\DateTimeImmutable $deletedAt,
        public readonly ?\DateTimeImmutable $movedAt,
    ) {
    }
}
