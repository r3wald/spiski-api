<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\DateTimeMsType;
use App\Repository\ShoppingListRepository;
use App\Sync\Dto\ListChange;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\NilUuid;
use Symfony\Component\Uid\Uuid;

/**
 * Spiegelt `ShoppingLists` aus dem Drift-Schema der App 1:1, erweitert um die
 * Sync-Felder. Bewusst ohne Fachlogik – das Backend ist reiner Sync-Speicher.
 */
#[ORM\Entity(repositoryClass: ShoppingListRepository::class)]
#[ORM\Table(name: 'shopping_list')]
#[ORM\Index(name: 'idx_list_user_version', columns: ['user_id', 'server_version'])]
class ShoppingList
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 100)]
    private string $name = '';

    /**
     * ARGB aus Flutters Color.toARGB32(), vorzeichenlos 32 Bit – passt nicht in
     * ein signed INT, daher BIGINT.
     */
    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $color = 0;

    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\Column(type: DateTimeMsType::NAME)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: DateTimeMsType::NAME)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: DateTimeMsType::NAME, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $serverVersion = 0;

    /** clientId des Geraets, dessen Schreibvorgang zuletzt gewonnen hat. */
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $lastClientId;

    /**
     * Teilen-Code (CLAUDE.md 12): zufaellig, unbefristet, bis der Besitzer ihn
     * erneuert oder das Teilen beendet. `null` = Liste wird nicht geteilt.
     */
    #[ORM\Column(length: 32, unique: true, nullable: true)]
    private ?string $shareCode = null;

    public function __construct(Uuid $id, User $user)
    {
        $this->id = $id;
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable('@0');
        $this->updatedAt = new \DateTimeImmutable('@0');
        // Platzhalter; apply() setzt den echten Wert, bevor die Zeile geschrieben wird.
        $this->lastClientId = new NilUuid();
    }

    /**
     * Uebernimmt eine gewonnene Push-Zeile. Alle Nutzfelder werden ersetzt, nicht
     * gemischt: LWW arbeitet zeilenweise, nicht feldweise (CLAUDE.md 5.3).
     */
    public function apply(ListChange $change, int $serverVersion, Uuid $clientId): void
    {
        $this->name = $change->name;
        $this->color = $change->color;
        $this->sortOrder = $change->sortOrder;
        $this->createdAt = $change->createdAt;
        $this->updatedAt = $change->updatedAt;
        $this->deletedAt = $change->deletedAt;
        $this->serverVersion = $serverVersion;
        $this->lastClientId = $clientId;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getColor(): int
    {
        return $this->color;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function getServerVersion(): int
    {
        return $this->serverVersion;
    }

    public function getLastClientId(): Uuid
    {
        return $this->lastClientId;
    }

    public function getShareCode(): ?string
    {
        return $this->shareCode;
    }

    public function setShareCode(?string $shareCode): void
    {
        $this->shareCode = $shareCode;
    }
}
