<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const PLAN_FREE = 'free';
    public const PLAN_PLUS = 'plus';

    public const LIMIT_FILE_FREE      = 2  * 1024 * 1024 * 1024;
    public const LIMIT_FILE_PLUS      = 20 * 1024 * 1024 * 1024;
    public const LIMIT_FILE_ANONYMOUS = 2  * 1024 * 1024 * 1024;

    public const LIMIT_STORAGE_FREE = 10  * 1024 * 1024 * 1024;
    public const LIMIT_STORAGE_PLUS = 200 * 1024 * 1024 * 1024;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private ?Uuid $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private string $password;

    #[ORM\Column(length: 10)]
    private string $plan = self::PLAN_FREE;

    /** Zobrazované jméno — nepovinné */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $name = null;

    /** MinIO klíč pro vlastní avatar — null = použít Gravatar */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $avatarKey = null;

    /** Nový email čekající na potvrzení */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $pendingEmail = null;

    /** Token pro potvrzení změny emailu */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $emailChangeToken = null;

    /** Expirace tokenu (24 hodin) */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailChangeTokenExpiry = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\OneToMany(targetEntity: File::class, mappedBy: 'user', cascade: ['remove'])]
    private Collection $files;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->files     = new ArrayCollection();
    }

    // ── Identity ────────────────────────────────────────────────────

    public function getId(): ?Uuid { return $this->id; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }

    public function getUserIdentifier(): string { return $this->email; }

    public function getRoles(): array
    {
        return array_unique(array_merge($this->roles, ['ROLE_USER']));
    }
    public function setRoles(array $roles): static { $this->roles = $roles; return $this; }

    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): static { $this->password = $password; return $this; }

    public function eraseCredentials(): void {}

    // ── Plan ────────────────────────────────────────────────────────

    public function getPlan(): string { return $this->plan; }
    public function setPlan(string $plan): static { $this->plan = $plan; return $this; }
    public function isPlus(): bool { return $this->plan === self::PLAN_PLUS; }
    public function isFree(): bool { return $this->plan === self::PLAN_FREE; }

    public function getFileSizeLimit(): int
    {
        return $this->isPlus() ? self::LIMIT_FILE_PLUS : self::LIMIT_FILE_FREE;
    }

    public function getStorageLimit(): int
    {
        return $this->isPlus() ? self::LIMIT_STORAGE_PLUS : self::LIMIT_STORAGE_FREE;
    }

    // ── Profile ─────────────────────────────────────────────────────

    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): static { $this->name = $name; return $this; }

    /** Zobrazované jméno — name pokud je, jinak část emailu */
    public function getDisplayName(): string
    {
        if ($this->name) return $this->name;
        return explode('@', $this->email)[0];
    }

    public function getAvatarKey(): ?string { return $this->avatarKey; }
    public function setAvatarKey(?string $key): static { $this->avatarKey = $key; return $this; }
    public function hasCustomAvatar(): bool { return $this->avatarKey !== null; }

    // ── Email change ─────────────────────────────────────────────────

    public function getPendingEmail(): ?string { return $this->pendingEmail; }
    public function setPendingEmail(?string $email): static { $this->pendingEmail = $email; return $this; }

    public function getEmailChangeToken(): ?string { return $this->emailChangeToken; }
    public function setEmailChangeToken(?string $token): static { $this->emailChangeToken = $token; return $this; }

    public function getEmailChangeTokenExpiry(): ?\DateTimeImmutable { return $this->emailChangeTokenExpiry; }
    public function setEmailChangeTokenExpiry(?\DateTimeImmutable $expiry): static { $this->emailChangeTokenExpiry = $expiry; return $this; }

    public function isEmailChangeTokenValid(): bool
    {
        return $this->emailChangeToken !== null
            && $this->emailChangeTokenExpiry !== null
            && $this->emailChangeTokenExpiry > new \DateTimeImmutable();
    }

    public function clearEmailChangeRequest(): static
    {
        $this->pendingEmail           = null;
        $this->emailChangeToken       = null;
        $this->emailChangeTokenExpiry = null;
        return $this;
    }

    // ── Password reset ───────────────────────────────────────────────

    /** Token pro obnovení hesla */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $resetToken = null;

    /** Expirace reset tokenu (1 hodina) */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resetTokenExpiry = null;

    public function getResetToken(): ?string { return $this->resetToken; }
    public function setResetToken(?string $token): static { $this->resetToken = $token; return $this; }

    public function getResetTokenExpiry(): ?\DateTimeImmutable { return $this->resetTokenExpiry; }
    public function setResetTokenExpiry(?\DateTimeImmutable $expiry): static { $this->resetTokenExpiry = $expiry; return $this; }

    public function isResetTokenValid(): bool
    {
        return $this->resetToken !== null
            && $this->resetTokenExpiry !== null
            && $this->resetTokenExpiry > new \DateTimeImmutable();
    }

    public function clearResetToken(): static
    {
        $this->resetToken       = null;
        $this->resetTokenExpiry = null;
        return $this;
    }

    // ── Timestamps ──────────────────────────────────────────────────

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getFiles(): Collection { return $this->files; }
}
