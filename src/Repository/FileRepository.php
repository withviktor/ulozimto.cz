<?php

namespace App\Repository;

use App\Entity\File;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

class FileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, File::class);
    }

    public function findByShareToken(string $token): ?File
    {
        return $this->findOneBy(['shareToken' => $token]);
    }

    /** @return File[] */
    public function findExpired(): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.expiresAt < :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    /** @return File[] */
    public function findByUser(int|string $userId): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Celkové využité úložiště uživatele v bytech. */
    public function getTotalStorageForUser(string $userId): int
    {
        $result = $this->createQueryBuilder('f')
            ->select('SUM(f.sizeBytes)')
            ->where('f.user = :userId')
            ->andWhere('f.expiresAt > :now')
            ->andWhere('f.scanStatus != :infected')
            ->setParameter('userId', $userId)
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('infected', File::SCAN_INFECTED)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }

    public function findByTokenOrAlias(string $tokenOrAlias): ?File
    {
        return $this->createQueryBuilder('f')
            ->where('f.shareToken = :val OR f.customAlias = :val')
            ->setParameter('val', $tokenOrAlias)
            ->getQuery()
            ->getOneOrNullResult();
    }

    // ── Admin queries ────────────────────────────────────────────────

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.scanStatus = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getTotalStorageAll(): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('SUM(f.sizeBytes)')
            ->where('f.expiresAt > :now')
            ->andWhere('f.scanStatus != :infected')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('infected', File::SCAN_INFECTED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Stránkovaný seznam všech souborů pro admin.
     * @return array{items: File[], total: int}
     */
    public function findPaginatedAll(int $page = 1, int $perPage = 40, ?string $status = null): array
    {
        $page = max(1, $page);
        $qb   = $this->createQueryBuilder('f')
            ->leftJoin('f.user', 'u')
            ->addSelect('u')
            ->orderBy('f.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        if ($status !== null) {
            $qb->where('f.scanStatus = :status')->setParameter('status', $status);
        }

        $paginator = new Paginator($qb);

        return [
            'items' => iterator_to_array($paginator),
            'total' => count($paginator),
        ];
    }
}
