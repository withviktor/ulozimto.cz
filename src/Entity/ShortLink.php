<?php

namespace App\Entity;

use App\Repository\ShortLinkRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ShortLinkRepository::class)]
#[ORM\Table(name: 'short_links')]
#[ORM\Index(columns: ['slug'], name: 'idx_slug')]
class ShortLink
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: File::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private File $file;

    /** Krátký slug pro URL, např. "ab12cd" */
    #[ORM\Column(length: 10, unique: true)]
    private string $slug;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Počet přístupů přes tento link */
    #[ORM\Column(type: 'integer')]
    private int $accessedCount = 0;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getFile(): File
    {
        return $this->file;
    }

    public function setFile(File $file): static
    {
        $this->file = $file;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAccessedCount(): int
    {
        return $this->accessedCount;
    }

    public function incrementAccessedCount(): static
    {
        $this->accessedCount++;

        return $this;
    }
}
