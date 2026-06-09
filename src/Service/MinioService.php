<?php

namespace App\Service;

use Aws\S3\S3Client;

/**
 * Wrapper nad AWS S3 SDK pro MinIO.
 *
 * Používá dva S3 klienty:
 *   $client       — pro vnitřní operace (upload, delete, stream) přes interní Docker síť
 *   $signerClient — pouze pro generování presigned URL, konfigurován s veřejnou doménou
 *
 * Důvod pro oddělené klienty:
 *   Presigned URL obsahuje HMAC-SHA256 podpis hlavičky Host. Pokud je URL podepsána
 *   s Host: minio:9000 (interní) ale prohlížeč pošle Host: cdn.ulozimto.cz (veřejná),
 *   MinIO vrátí SignatureDoesNotMatch. Generování presigned URL je čistě offline
 *   matematická operace — signerClient neprovádí žádné síťové volání na veřejnou doménu.
 */
class MinioService
{
    private S3Client $client;
    private S3Client $signerClient;
    private string   $bucket;

    public function __construct(
        string $endpoint,
        string $key,
        string $secret,
        string $region,
        string $bucket,
        string $publicUrl,
    ) {
        $this->bucket = $bucket;

        $sharedConfig = [
            'version'                 => 'latest',
            'region'                  => $region,
            'use_path_style_endpoint' => true,
            'credentials'             => [
                'key'    => $key,
                'secret' => $secret,
            ],
        ];

        // Vnitřní klient — rychlý přístup přes Docker síť (minio:9000)
        $this->client = new S3Client($sharedConfig + ['endpoint' => rtrim($endpoint, '/')]);

        // Podepisovací klient — URL generuje přímo s veřejnou doménou (cdn.ulozimto.cz)
        $this->signerClient = new S3Client($sharedConfig + ['endpoint' => rtrim($publicUrl, '/')]);
    }

    // ── Multipart upload ─────────────────────────────────────────────

    public function createMultipartUpload(string $key, string $mimeType): string
    {
        $result = $this->client->createMultipartUpload([
            'Bucket'      => $this->bucket,
            'Key'         => $key,
            'ContentType' => $mimeType,
        ]);

        return $result['UploadId'];
    }

    /** @param resource|string $body */
    public function uploadPart(string $key, string $uploadId, int $partNumber, mixed $body): string
    {
        $result = $this->client->uploadPart([
            'Bucket'     => $this->bucket,
            'Key'        => $key,
            'UploadId'   => $uploadId,
            'PartNumber' => $partNumber,
            'Body'       => $body,
        ]);

        return $result['ETag'];
    }

    /** @param array<array{PartNumber: int, ETag: string}> $parts */
    public function completeMultipartUpload(string $key, string $uploadId, array $parts): void
    {
        $this->client->completeMultipartUpload([
            'Bucket'          => $this->bucket,
            'Key'             => $key,
            'UploadId'        => $uploadId,
            'MultipartUpload' => ['Parts' => $parts],
        ]);
    }

    public function abortMultipartUpload(string $key, string $uploadId): void
    {
        $this->client->abortMultipartUpload([
            'Bucket'   => $this->bucket,
            'Key'      => $key,
            'UploadId' => $uploadId,
        ]);
    }

    // ── Jednoduchý upload ─────────────────────────────────────────────

    public function putObject(string $key, mixed $body, string $mimeType): void
    {
        $this->client->putObject([
            'Bucket'      => $this->bucket,
            'Key'         => $key,
            'Body'        => $body,
            'ContentType' => $mimeType,
        ]);
    }

    // ── Presigned URL ─────────────────────────────────────────────────

    /**
     * Vygeneruje presigned URL podepsanou přímo s veřejnou doménou.
     *
     * signerClient má jako endpoint nastavenou veřejnou URL (cdn.ulozimto.cz),
     * takže podpis bude odpovídat hlavičce Host, kterou prohlížeč pošle.
     * Žádný str_replace není potřeba.
     */
    public function getPresignedUrl(string $key, int $expiresInSeconds = 3600): string
    {
        $cmd = $this->signerClient->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key'    => $key,
        ]);

        $request = $this->signerClient->createPresignedRequest($cmd, "+{$expiresInSeconds} seconds");

        return (string) $request->getUri();
    }

    // ── Mazání ───────────────────────────────────────────────────────

    public function delete(string $key): void
    {
        $this->client->deleteObject([
            'Bucket' => $this->bucket,
            'Key'    => $key,
        ]);
    }

    // ── Bucket bootstrap ─────────────────────────────────────────────

    public function ensureBucketExists(): void
    {
        if (!$this->client->doesBucketExist($this->bucket)) {
            $this->client->createBucket(['Bucket' => $this->bucket]);
        }
    }

    // ── Stream proxy ─────────────────────────────────────────────────

    /**
     * Vrátí obsah souboru jako PHP stream.
     * Volitelný range pro HTTP 206 Partial Content (video/audio seeking).
     *
     * @return resource
     */
    public function getObjectStream(string $key, ?int $start = null, ?int $end = null): mixed
    {
        $params = [
            'Bucket' => $this->bucket,
            'Key'    => $key,
        ];

        if ($start !== null) {
            $params['Range'] = 'bytes=' . $start . '-' . ($end ?? '');
        }

        $result = $this->client->getObject($params);

        return $result['Body']->detach();
    }

    // ── Helpers ───────────────────────────────────────────────────────

    public function generateKey(string $filename): string
    {
        return sprintf(
            'files/%s/%s/%s',
            date('Y/m'),
            bin2hex(random_bytes(8)),
            $filename
        );
    }
}
