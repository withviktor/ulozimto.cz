<?php

namespace App\Repository;

use App\Entity\File;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    /** Celkové využité úložiště uživatele v bytech (nezahrnuje expirované a infikované soubory). */
    public function getTotalStorageForUser(string $userId): int
    {
        $result = $this->createQueryBuilder('f')
            ->select('SUM(f.sizeBytes)')
            ->where('f.user = :userId')
            ->andWhere('f.expiresAt > :now')
            ->andWhere('f.scanStatus != :infected')
            ->setParameter('userId', $userId)
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('infected', \App\Entity\File::SCAN_INFECTED)
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
}
