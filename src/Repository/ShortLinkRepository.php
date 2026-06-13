<?php

namespace App\Repository;

use App\Entity\ShortLink;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShortLink>
 */
class ShortLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShortLink::class);
    }

    /**
     * Najde short link podle slug
     */
    public function findBySlug(string $slug): ?ShortLink
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Generuje jedinečný slug pro nový short link
     */
    public function generateUniqueSlug(int $length = 6): string
    {
        $maxAttempts = 100;
        $attempts = 0;

        do {
            $slug = $this->generateRandomSlug($length);
            $attempts++;
        } while ($this->findBySlug($slug) !== null && $attempts < $maxAttempts);

        if ($attempts >= $maxAttempts) {
            throw new \RuntimeException('Failed to generate unique slug after ' . $maxAttempts . ' attempts');
        }

        return $slug;
    }

    /**
     * Generuje náhodný slug o zadané délce
     */
    private function generateRandomSlug(int $length = 6): string
    {
        $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $slug = '';
        for ($i = 0; $i < $length; $i++) {
            $slug .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $slug;
    }

    /**
     * Najde všechny short linky uživatele
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('sl')
            ->join('sl.file', 'f')
            ->where('f.user = :user')
            ->setParameter('user', $user)
            ->orderBy('sl.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Spočítá short linky uživatele
     */
    public function countByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('sl')
            ->select('COUNT(sl.id)')
            ->join('sl.file', 'f')
            ->where('f.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Vrátí short linky uživatele se zápisem o souboru (pro dashboard)
     * Zahrnuje filtrování, hledání a stránkování
     */
    public function findByUserWithFileInfo(User $user, string $search = '', string $sortBy = 'created_desc', int $page = 1, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('sl')
            ->join('sl.file', 'f')
            ->where('f.user = :user')
            ->setParameter('user', $user);

        // Hledání
        if (!empty($search)) {
            $qb->andWhere('f.originalName LIKE :search OR sl.slug LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        // Řazení
        match ($sortBy) {
            'created_asc' => $qb->orderBy('sl.createdAt', 'ASC'),
            'clicks_desc' => $qb->orderBy('sl.accessedCount', 'DESC'),
            'clicks_asc' => $qb->orderBy('sl.accessedCount', 'ASC'),
            'size_desc' => $qb->orderBy('f.sizeBytes', 'DESC'),
            'size_asc' => $qb->orderBy('f.sizeBytes', 'ASC'),
            'expires_asc' => $qb->orderBy('f.expiresAt', 'ASC'),
            default => $qb->orderBy('sl.createdAt', 'DESC'),
        };

        // Stránkování
        $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    /**
     * Vrátí nejpopulárnější short linky uživatele
     */
    public function findMostPopular(User $user, int $limit = 10): array
    {
        return $this->createQueryBuilder('sl')
            ->join('sl.file', 'f')
            ->where('f.user = :user')
            ->setParameter('user', $user)
            ->orderBy('sl.accessedCount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Vrátí nedávno vytvořené short linky uživatele
     */
    public function findRecentlyCreated(User $user, int $limit = 10): array
    {
        return $this->createQueryBuilder('sl')
            ->join('sl.file', 'f')
            ->where('f.user = :user')
            ->setParameter('user', $user)
            ->orderBy('sl.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Zjistí, zda uživatel vlastní daný short link
     */
    public function isOwnedBy(ShortLink $shortLink, User $user): bool
    {
        $result = $this->createQueryBuilder('sl')
            ->select('COUNT(sl.id)')
            ->where('sl.id = :id')
            ->join('sl.file', 'f')
            ->where('sl.id = :id AND f.user = :user')
            ->setParameter('id', $shortLink->getId())
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return $result > 0;
    }
}
