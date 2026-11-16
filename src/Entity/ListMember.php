<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\DateTimeMsType;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ein Konto, mit dem eine Liste geteilt wurde (CLAUDE.md 12). Der Besitzer ist
 * kein Mitglied, er steht in `shopping_list.user_id`.
 *
 * Gelesen und geschrieben wird ueber DBAL (App\Sharing\ListAccess); die
 * Entity existiert, damit Schema und Migrations zum Mapping passen.
 */
#[ORM\Entity]
#[ORM\Table(name: 'list_member')]
#[ORM\Index(name: 'idx_list_member_user', columns: ['user_id'])]
class ListMember
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: ShoppingList::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ShoppingList $list;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: DateTimeMsType::NAME)]
    private \DateTimeImmutable $joinedAt;

    /**
     * Listenschluessel, mit dem Kontoschluessel des Mitglieds verpackt
     * (CLAUDE.md 14); `null` bei Klartext-Listen.
     */
    #[ORM\Column(length: 256, nullable: true)]
    private ?string $wrappedKey = null;

    public function __construct(ShoppingList $list, User $user, \DateTimeImmutable $joinedAt)
    {
        $this->list = $list;
        $this->user = $user;
        $this->joinedAt = $joinedAt;
    }

    public function getList(): ShoppingList
    {
        return $this->list;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function getWrappedKey(): ?string
    {
        return $this->wrappedKey;
    }
}
