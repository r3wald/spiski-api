<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ShoppingItem;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ShoppingItem>
 */
class ShoppingItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShoppingItem::class);
    }

    /**
     * Laedt Zeilen unabhaengig vom Konto. Ob der Aufrufer sie sehen oder
     * aendern darf, entscheidet App\Sharing\ListAccess (CLAUDE.md 12).
     *
     * @param list<Uuid|string> $ids
     *
     * @return array<string, ShoppingItem> indiziert nach RFC-4122-Darstellung der ID
     */
    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<ShoppingItem> $rows */
        $rows = $this->createQueryBuilder('i')
            ->andWhere('i.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (Uuid|string $id): string => $id instanceof Uuid ? $id->toRfc4122() : $id, $ids))
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($rows as $row) {
            $byId[$row->getId()->toRfc4122()] = $row;
        }

        return $byId;
    }

    public function countForUser(User $user): int
    {
        /** @var int|string $count */
        $count = $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }
}
