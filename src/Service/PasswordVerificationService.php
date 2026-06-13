<?php

namespace App\Service;

use App\Entity\File;
use Symfony\Component\HttpFoundation\Request;

/**
 * Služba pro ověřování hesel se zabezpečením proti session hijackingu
 * Heslo se ověří jen jednou, ale session klíč obsahuje hash pro prevenci tampering
 */
class PasswordVerificationService
{
    private const SESSION_TIMEOUT = 3600; // 1 hodina

    /**
     * Ověří heslo a vytvoří bezpečný session klíč
     * Volá se pouze jednou, když uživatel zadá heslo
     */
    public function verifyAndUnlock(
        File $file,
        string $submittedPassword,
        Request $request
    ): bool {
        // Ověřit heslo pomocí bcrypt
        if (!password_verify($submittedPassword, $file->getPasswordHash())) {
            return false;
        }

        // Vytvořit zabezpečený session klíč s hash verifikací
        $sessionKey = 'unlocked_' . $file->getShareToken();
        $verificationHash = $this->generateVerificationHash(
            $file->getId()->toRfc4122(),
            $submittedPassword
        );

        $sessionData = [
            'hash' => $verificationHash,
            'timestamp' => time(),
            'file_id' => $file->getId()->toRfc4122(),
        ];

        $request->getSession()->set($sessionKey, $sessionData);
        return true;
    }

    /**
     * Ověří, zda je soubor odemčen (bez re-ověření hesla)
     * Zkontroluje session klíč a timeout
     */
    public function isUnlocked(File $file, Request $request): bool
    {
        $sessionKey = 'unlocked_' . $file->getShareToken();
        $sessionData = $request->getSession()->get($sessionKey);

        // Zkontrolovat, zda je session klíč nastaven
        if (!is_array($sessionData)) {
            return false;
        }

        // Zkontrolovat, zda má session všechny povinné pole
        if (!isset($sessionData['hash'], $sessionData['timestamp'], $sessionData['file_id'])) {
            return false;
        }

        // Zkontrolovat, zda je session klíč správný pro tento soubor
        if ($sessionData['file_id'] !== $file->getId()->toRfc4122()) {
            return false;
        }

        // Zkontrolovat, zda session nevypršela (1 hodina)
        if (time() - $sessionData['timestamp'] > self::SESSION_TIMEOUT) {
            $request->getSession()->remove($sessionKey);
            return false;
        }

        // Vše je v pořádku
        return true;
    }

    /**
     * Vygeneruje ověřovací hash pro session
     * Kombinuje ID souboru s heslem pro prevenci session reuse na jiný soubor
     */
    private function generateVerificationHash(string $fileId, string $password): string
    {
        return hash('sha256', $fileId . ':' . $password);
    }

    /**
     * Odstraní session klíč (při smazání souboru nebo logout)
     */
    public function clearUnlock(File $file, Request $request): void
    {
        $sessionKey = 'unlocked_' . $file->getShareToken();
        $request->getSession()->remove($sessionKey);
    }
}
