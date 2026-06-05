<?php

namespace App\Service;

/**
 * ClamAV klient — komunikuje s ClamAV daemon přes TCP socket pomocí INSTREAM protokolu.
 * Soubor se streamuje přímo, bez nutnosti temp souboru.
 */
class ClamAvService
{
    private const CHUNK_SIZE = 8192; // 8 KB

    public function __construct(
        private readonly string $host,
        private readonly int    $port,
        private readonly int    $timeout = 30,
    ) {}

    /**
     * Skenuje stream a vrátí výsledek.
     *
     * @param resource $stream
     * @return string 'clean' | 'infected' | 'error'
     */
    public function scanStream(mixed $stream): string
    {
        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);

        if (!$socket) {
            throw new \RuntimeException("Nelze se připojit k ClamAV ({$this->host}:{$this->port}): {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, $this->timeout);

        try {
            // Zahájit INSTREAM sken
            fwrite($socket, "zINSTREAM\0");

            // Odeslat data v chunkcích s 4-bytovým prefixem délky (big-endian)
            while (!feof($stream)) {
                $chunk = fread($stream, self::CHUNK_SIZE);
                if ($chunk === false || strlen($chunk) === 0) break;
                fwrite($socket, pack('N', strlen($chunk)) . $chunk);
            }

            // Ukončit stream nulovým chunkem
            fwrite($socket, pack('N', 0));

            // Přečíst odpověď ClamAV
            $response = '';
            while (!feof($socket)) {
                $response .= fread($socket, 512);
                if (str_contains($response, "\0") || str_contains($response, "\n")) break;
            }

            $response = trim($response, "\0\n");

        } finally {
            fclose($socket);
            if (is_resource($stream)) fclose($stream);
        }

        if (str_contains($response, 'FOUND')) {
            return 'infected';
        }

        if (str_contains($response, 'OK')) {
            return 'clean';
        }

        return 'error';
    }

    /** Zkontroluje dostupnost ClamAV daemona. */
    public function ping(): bool
    {
        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, 3);
        if (!$socket) return false;

        fwrite($socket, "zPING\0");
        $response = fread($socket, 32);
        fclose($socket);

        return str_contains($response, 'PONG');
    }
}
