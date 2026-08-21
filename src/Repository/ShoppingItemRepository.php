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
     * Pull-Fenster: alle Artikel mit serverVersion > $since, aufsteigend.
     *
     * @return list<ShoppingItem>
     */
    public function findChangedSince(User $user, int $since, int $limit): array
    {
        /** @var list<ShoppingItem> */
        return $this->createQueryBuilder('i')
            ->andWhere('i.user = :user')
            ->andWhere('i.serverVersion > :since')
            ->setParameter('user', $user)
            ->setParameter('since', $since)
            ->orderBy('i.serverVersion', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return array<string, ShoppingItem> indiziert nach RFC-4122-Darstellung der ID
     */
    public function findOwnedByIds(User $user, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<ShoppingItem> $rows */
        $rows = $this->createQueryBuilder('i')
            ->andWhere('i.user = :user')
            ->andWhere('i.id IN (:ids)')
            ->setParameter('user', $user)
            ->setParameter('ids', $ids)
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
