<?php

declare(strict_types=1);

namespace App\Sync\Dto;

use Symfony\Component\Uid\Uuid;

/**
 * Eine vom Client gepushte Listen-Zeile, bereits geparst, validiert und mit
 * gekapptem `updatedAt` (siehe App\Sync\ClockSkewGuard).
 */
final class ListChange
{
    public function __construct(
        public readonly Uuid $id,
        public readonly string $name,
        public readonly int $color,
        public readonly int $sortOrder,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
        public readonly ?\DateTimeImmutable $deletedAt,
    ) {
    }
}
