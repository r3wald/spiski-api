<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Was ein Konto beim Pull zu sehen bekommt: je sichtbarer Zeile die Version
 * aus dem Zaehler *dieses* Kontos (CLAUDE.md 5.2 und 12).
 *
 * Mit geteilten Listen sieht ein Konto auch Zeilen, die ein anderes geschrieben
 * hat. Jede akzeptierte Aenderung bekommt deshalb in jedem betroffenen Konto
 * eine eigene, dort lueckenlose Version. `kind = revoked` meldet eine Liste,
 * auf die das Konto keinen Zugriff mehr hat; `row_id` ist dann die Listen-ID.
 *
 * Gelesen und geschrieben wird ueber DBAL (App\Sync\VersionPublisher).
 */
#[ORM\Entity]
#[ORM\Table(name: 'sync_view')]
#[ORM\Index(name: 'idx_sync_view_user_version', columns: ['user_id', 'version'])]
class SyncView
{
    public const KIND_LIST = 'list';
    public const KIND_ITEM = 'item';
    public const KIND_REVOKED = 'revoked';

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Id]
    #[ORM\Column(length: 16)]
    private string $kind;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $rowId;

    #[ORM\Column(type: Types::BIGINT)]
    private int $version;

    public function __construct(User $user, string $kind, Uuid $rowId, int $version)
    {
        $this->user = $user;
        $this->kind = $kind;
        $this->rowId = $rowId;
        $this->version = $version;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getRowId(): Uuid
    {
        return $this->rowId;
    }

    public function getVersion(): int
    {
        return $this->version;
    }
}
