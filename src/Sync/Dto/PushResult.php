<?php

declare(strict_types=1);

namespace App\Sync\Dto;

use Symfony\Component\Uid\Uuid;

/**
 * Rueckmeldung zu genau einer gepushten Zeile.
 */
final class PushResult
{
    private function __construct(
        public readonly Uuid $id,
        public readonly bool $accepted,
        public readonly ?RejectReason $reason,
    ) {
    }

    public static function accepted(Uuid $id): self
    {
        return new self($id, true, null);
    }

    public static function rejected(Uuid $id, RejectReason $reason): self
    {
        return new self($id, false, $reason);
    }
}
