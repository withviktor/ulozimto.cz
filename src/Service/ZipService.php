<?php

namespace App\Service;

/**
 * Zabalí více souborů (z dočasného umístění) do jednoho ZIP archivu.
 * Vrátí cestu k dočasnému ZIP souboru — volající ho musí po nahrání smazat.
 */
class ZipService
{
    /**
     * @param array<array{tmpPath: string, name: string}> $files
     */
    public function createZip(array $files, string $archiveName): string
    {
        $zipPath = sys_get_temp_dir() . '/' . uniqid('ulozimto_', true) . '.zip';

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Nelze vytvořit ZIP archiv: {$zipPath}");
        }

        foreach ($files as $file) {
            if (!file_exists($file['tmpPath'])) {
                continue;
            }
            $zip->addFile($file['tmpPath'], $file['name']);
        }

        $zip->close();

        return $zipPath;
    }
}
