<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\DateTimeMsType;
use App\Repository\ShoppingItemRepository;
use App\Sync\Dto\ItemChange;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\NilUuid;
use Symfony\Component\Uid\Uuid;

/**
 * Spiegelt `ShoppingItems` aus dem Drift-Schema der App 1:1, erweitert um die
 * Sync-Felder und um `user` (in der App nicht vorhanden).
 */
#[ORM\Entity(repositoryClass: ShoppingItemRepository::class)]
#[ORM\Table(name: 'shopping_item')]
#[ORM\Index(name: 'idx_item_user_version', columns: ['user_id', 'server_version'])]
class ShoppingItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /**
     * Denormalisiert. Der Pull filtert nach (user, serverVersion); ohne diese
     * Spalte braeuchte jede Abfrage einen Join ueber shopping_list.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: ShoppingList::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ShoppingList $list;

    #[ORM\Column(length: 200)]
    private string $name = '';

    #[ORM\Column(nullable: true)]
    private ?float $quantity = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $unit = null;

    #[ORM\Column]
    private bool $isChecked = false;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $note = null;

    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\Column(type: DateTimeMsType::NAME)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: DateTimeMsType::NAME)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: DateTimeMsType::NAME, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(type: DateTimeMsType::NAME, nullable: true)]
    private ?\DateTimeImmutable $movedAt = null;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $serverVersion = 0;

    /** clientId des Geraets, dessen Schreibvorgang zuletzt gewonnen hat. */
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $lastClientId;

    /**
     * Konto, dessen Schreibvorgang zuletzt gewonnen hat. Nur serverintern: Der
     * Pull meldet daraus `changedByOther` (Benachrichtigungen in der App), die
     * ID selbst verlaesst den Server nie. Ohne Fremdschluessel, damit das
     * Loeschen eines Kontos die Artikel anderer nicht beruehrt; `null` bei
     * Artikeln aus der Zeit vor dieser Spalte.
     */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $lastUserId = null;

    /**
     * Verschluesselter Inhalt (Name, Menge, Einheit, Notiz; CLAUDE.md 14),
     * `null` bei Klartext-Zeilen. Bei verschluesselten Zeilen sind `name` (leerer
     * Platzhalter), `quantity`, `unit` und `note` leer.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $enc = null;

    public function __construct(Uuid $id, User $user, ShoppingList $list)
    {
        $this->id = $id;
        $this->user = $user;
        $this->list = $list;
        $this->createdAt = new \DateTimeImmutable('@0');
        $this->updatedAt = new \DateTimeImmutable('@0');
        // Platzhalter; apply() setzt den echten Wert, bevor die Zeile geschrieben wird.
        $this->lastClientId = new NilUuid();
    }

    /**
     * Uebernimmt eine gewonnene Push-Zeile. `$list` wird mitgegeben, weil ein
     * Artikel per LWW auch in eine andere Liste verschoben werden kann.
     */
    public function apply(
        ItemChange $change,
        ShoppingList $list,
        int $serverVersion,
        Uuid $clientId,
        Uuid $userId,
    ): void {
        $this->list = $list;
        // Ein Artikel zaehlt immer zum Besitzer seiner Liste – auch wenn ihn ein
        // Mitglied einer geteilten Liste angelegt hat (Kontingent, CLAUDE.md 12).
        $this->user = $list->getUser();
        // Verschluesselt: Der Parser laesst Menge, Einheit und Notiz dann leer;
        // so bleibt beim Umstellen kein Klartext zurueck (CLAUDE.md 14).
        $this->name = $change->name ?? '';
        $this->enc = $change->enc;
        $this->quantity = $change->quantity;
        $this->unit = $change->unit;
        $this->isChecked = $change->isChecked;
        $this->note = $change->note;
        $this->sortOrder = $change->sortOrder;
        $this->createdAt = $change->createdAt;
        $this->updatedAt = $change->updatedAt;
        $this->deletedAt = $change->deletedAt;
        $this->movedAt = $change->movedAt;
        $this->serverVersion = $serverVersion;
        $this->lastClientId = $clientId;
        $this->lastUserId = $userId;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getList(): ShoppingList
    {
        return $this->list;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getQuantity(): ?float
    {
        return $this->quantity;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function isChecked(): bool
    {
        return $this->isChecked;
    }

    public function getNote(): ?string
    {
        return $this->note;
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

    public function getMovedAt(): ?\DateTimeImmutable
    {
        return $this->movedAt;
    }

    public function getServerVersion(): int
    {
        return $this->serverVersion;
    }

    public function getLastClientId(): Uuid
    {
        return $this->lastClientId;
    }

    public function getEnc(): ?string
    {
        return $this->enc;
    }

    public function isEncrypted(): bool
    {
        return null !== $this->enc;
    }

    public function getLastUserId(): ?Uuid
    {
        return $this->lastUserId;
    }
}
