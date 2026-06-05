<?php

namespace App\Entity;

use App\Repository\FileRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: FileRepository::class)]
#[ORM\Table(name: 'files')]
#[ORM\Index(columns: ['share_token'], name: 'idx_share_token')]
#[ORM\Index(columns: ['expires_at'],  name: 'idx_expires_at')]
class File
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private ?Uuid $id = null;

    /** Krátký token pro share URL, např. aB3x9Kzm */
    #[ORM\Column(length: 12, unique: true)]
    private string $shareToken;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'files')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(length: 255)]
    private string $originalName;

    #[ORM\Column(length: 127, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(type: 'bigint')]
    private int $sizeBytes = 0;

    /** Cesta k souboru v MinIO bucketu */
    #[ORM\Column(length: 512)]
    private string $minioKey;

    /** Bcrypt hash hesla, null = bez ochrany */
    #[ORM\Column(nullable: true)]
    private ?string $passwordHash = null;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'integer')]
    private int $downloadCount = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid { return $this->id; }

    public function getShareToken(): string { return $this->shareToken; }
    public function setShareToken(string $t): static { $this->shareToken = $t; return $this; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $u): static { $this->user = $u; return $this; }

    public function getOriginalName(): string { return $this->originalName; }
    public function setOriginalName(string $n): static { $this->originalName = $n; return $this; }

    public function getMimeType(): ?string { return $this->mimeType; }
    public function setMimeType(?string $m): static { $this->mimeType = $m; return $this; }

    public function getSizeBytes(): int { return $this->sizeBytes; }
    public function setSizeBytes(int $s): static { $this->sizeBytes = $s; return $this; }

    public function getMinioKey(): string { return $this->minioKey; }
    public function setMinioKey(string $k): static { $this->minioKey = $k; return $this; }

    public function getPasswordHash(): ?string { return $this->passwordHash; }
    public function setPasswordHash(?string $h): static { $this->passwordHash = $h; return $this; }

    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $e): static { $this->expiresAt = $e; return $this; }

    public function getDownloadCount(): int { return $this->downloadCount; }
    public function incrementDownloadCount(): static { $this->downloadCount++; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function isExpired(): bool { return $this->expiresAt < new \DateTimeImmutable(); }
    public function isPasswordProtected(): bool { return $this->passwordHash !== null; }

    public function getFormattedSize(): string
    {
        $b = $this->sizeBytes;
        if ($b < 1024)       return $b . ' B';
        if ($b < 1_048_576)  return round($b / 1024, 1) . ' KB';
        if ($b < 1_073_741_824) return round($b / 1_048_576, 1) . ' MB';
        return round($b / 1_073_741_824, 2) . ' GB';
    }
}
