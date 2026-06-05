<?php

namespace App\Service;

use App\Entity\User;

class FileExpirationService
{
    /**
     * Možnosti expirace v hodinách dle plánu.
     * Klíč = hodiny, hodnota = label pro UI.
     */
    public const OPTIONS_ANONYMOUS = [
        1   => '1 hodina',
        24  => '24 hodin',
        72  => '3 dny',
    ];

    public const OPTIONS_FREE = [
        168  => '7 dní',
        720  => '30 dní',
        2160 => '90 dní',
    ];

    public const OPTIONS_PLUS = [
        168  => '7 dní',
        720  => '30 dní',
        2160 => '90 dní',
        4320 => '180 dní',
        8760 => '1 rok',
    ];

    public const DEFAULT_HOURS_ANONYMOUS = 72;
    public const DEFAULT_HOURS_FREE      = 720;   // 30 dní
    public const DEFAULT_HOURS_PLUS      = 720;   // 30 dní

    public const MAX_HOURS_ANONYMOUS = 72;
    public const MAX_HOURS_FREE      = 2160;  // 90 dní
    public const MAX_HOURS_PLUS      = 8760;  // 365 dní

    /** @return array<int, string> hodiny => label */
    public function getOptions(?User $user): array
    {
        if ($user === null)       return self::OPTIONS_ANONYMOUS;
        if ($user->isPlus())      return self::OPTIONS_PLUS;
        return self::OPTIONS_FREE;
    }

    public function getDefaultHours(?User $user): int
    {
        if ($user === null)  return self::DEFAULT_HOURS_ANONYMOUS;
        if ($user->isPlus()) return self::DEFAULT_HOURS_PLUS;
        return self::DEFAULT_HOURS_FREE;
    }

    public function getMaxHours(?User $user): int
    {
        if ($user === null)  return self::MAX_HOURS_ANONYMOUS;
        if ($user->isPlus()) return self::MAX_HOURS_PLUS;
        return self::MAX_HOURS_FREE;
    }

    /**
     * Vypočítá datum expirace z počtu hodin.
     * Automaticky ořízne na maximum pro daný plán.
     */
    public function getExpirationDate(int $hours, ?User $user): \DateTimeImmutable
    {
        $max = $this->getMaxHours($user);

        if ($hours <= 0 || $hours > $max) {
            $hours = $this->getDefaultHours($user);
        }

        return new \DateTimeImmutable("+{$hours} hours");
    }

    // ----------------------------------------------------------------
    // Pomocné metody pro UI
    // ----------------------------------------------------------------

    public function getRemainingLabel(\DateTimeImmutable $expiresAt): string
    {
        $now  = new \DateTimeImmutable();
        $diff = $now->diff($expiresAt);

        if ($expiresAt < $now) {
            return 'Expirováno';
        }

        $totalHours = (int) ($diff->days * 24 + $diff->h);

        if ($diff->days === 0 && $diff->h < 1) {
            return 'Vyprší za méně než hodinu';
        }

        if ($diff->days === 0) {
            return "Zbývá {$diff->h} " . $this->hourLabel($diff->h);
        }

        return "Zbývá {$diff->days} " . $this->dayLabel($diff->days);
    }

    private function dayLabel(int $days): string
    {
        if ($days === 1) return 'den';
        if ($days <= 4)  return 'dny';
        return 'dní';
    }

    private function hourLabel(int $hours): string
    {
        if ($hours === 1) return 'hodinu';
        if ($hours <= 4)  return 'hodiny';
        return 'hodin';
    }
}
