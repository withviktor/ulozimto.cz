<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class AvatarService
{
    private const AVATAR_MAX_SIZE  = 2 * 1024 * 1024;  // 2 MB
    private const ALLOWED_MIMES    = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    private const AVATAR_BUCKET_PREFIX = 'avatars/';

    public function __construct(
        private readonly MinioService $minio,
    ) {}

    /**
     * Vrátí URL avataru — vlastní (MinIO proxy) nebo Gravatar fallback.
     */
    public function getAvatarUrl(User $user, int $size = 80): string
    {
        if ($user->hasCustomAvatar()) {
            return '/profile/avatar/' . $user->getId()->toRfc4122();
        }

        return $this->gravatarUrl($user->getEmail(), $size);
    }

    public function gravatarUrl(string $email, int $size = 80): string
    {
        $hash = md5(strtolower(trim($email)));
        return "https://gravatar.com/avatar/{$hash}?s={$size}&d=identicon";
    }

    /**
     * Nahraje avatar do MinIO, uloží klíč do entity.
     * Vrátí chybovou zprávu nebo null pokud vše proběhlo v pořádku.
     */
    public function upload(User $user, UploadedFile $file): ?string
    {
        if (!in_array($file->getMimeType(), self::ALLOWED_MIMES, true)) {
            return 'Nepodporovaný formát. Povoleny jsou: JPG, PNG, GIF, WebP.';
        }

        if ($file->getSize() > self::AVATAR_MAX_SIZE) {
            return 'Soubor je příliš velký. Maximum je 2 MB.';
        }

        // Smazat starý avatar
        if ($user->hasCustomAvatar()) {
            $this->deleteAvatar($user);
        }

        $ext = $file->guessExtension() ?? 'jpg';
        $key = self::AVATAR_BUCKET_PREFIX . $user->getId()->toRfc4122() . '.' . $ext;

        $this->minio->putObject($key, fopen($file->getPathname(), 'rb'), $file->getMimeType() ?? 'image/jpeg');

        $user->setAvatarKey($key);

        return null;
    }

    /**
     * Smaže vlastní avatar z MinIO.
     */
    public function deleteAvatar(User $user): void
    {
        if (!$user->hasCustomAvatar()) return;

        try {
            $this->minio->delete($user->getAvatarKey());
        } catch (\Throwable) {
            // Ignorovat chybu smazání — klíč nemusí existovat
        }

        $user->setAvatarKey(null);
    }
}
