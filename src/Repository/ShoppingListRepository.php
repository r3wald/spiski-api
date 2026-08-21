<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ShoppingList;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ShoppingList>
 */
class ShoppingListRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShoppingList::class);
    }

    /**
     * Pull-Fenster: alle Listen mit serverVersion > $since, aufsteigend.
     *
     * @return list<ShoppingList>
     */
    public function findChangedSince(User $user, int $since, int $limit): array
    {
        /** @var list<ShoppingList> */
        return $this->createQueryBuilder('l')
            ->andWhere('l.user = :user')
            ->andWhere('l.serverVersion > :since')
            ->setParameter('user', $user)
            ->setParameter('since', $since)
            ->orderBy('l.serverVersion', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Laedt die gepushten Zeilen in einer einzigen Abfrage; der Push umfasst
     * hoechstens SyncLimits::MAX_PUSH_ROWS Zeilen.
     *
     * Die Einschraenkung auf $user ist die Ownership-Pruefung: eine fremde ID
     * liefert schlicht keinen Treffer und wird dadurch wie eine neue Zeile
     * behandelt bzw. bei Artikeln als unknown_list abgelehnt.
     *
     * @param list<Uuid> $ids
     *
     * @return array<string, ShoppingList> indiziert nach RFC-4122-Darstellung der ID
     */
    public function findOwnedByIds(User $user, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<ShoppingList> $rows */
        $rows = $this->createQueryBuilder('l')
            ->andWhere('l.user = :user')
            ->andWhere('l.id IN (:ids)')
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
        $count = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }
}
