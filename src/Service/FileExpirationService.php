<?php

namespace App\Service;

class FileExpirationService
{
    /** @var int[] Povolené doby expirace v dnech */
    public const ALLOWED_DAYS = [7, 30, 90];
    public const DEFAULT_DAYS = 30;

    public function getExpirationDate(int $days): \DateTimeImmutable
    {
        if (!in_array($days, self::ALLOWED_DAYS, true)) {
            $days = self::DEFAULT_DAYS;
        }

        return new \DateTimeImmutable("+{$days} days");
    }

    public function getRemainingLabel(\DateTimeImmutable $expiresAt): string
    {
        $now  = new \DateTimeImmutable();
        $diff = $now->diff($expiresAt);

        if ($expiresAt < $now) {
            return 'Expirováno';
        }

        if ($diff->days === 0) {
            return 'Dnes vyprší';
        }

        return "Zbývá {$diff->days} " . $this->dayLabel($diff->days);
    }

    private function dayLabel(int $days): string
    {
        if ($days === 1) return 'den';
        if ($days <= 4) return 'dny';
        return 'dní';
    }
}
