<?php

namespace App\Service;

use Aws\S3\S3Client;

/**
 * Wrapper nad AWS S3 SDK pro MinIO.
 * Flysystem neexponuje multipart upload API přímo,
 * proto používáme S3Client přímo pro chunked uploady.
 */
class MinioService
{
    private S3Client $client;
    private string $bucket;
    private string $endpoint;
    private string $publicUrl;

    public function __construct(
        string $endpoint,
        string $key,
        string $secret,
        string $region,
        string $bucket,
        string $publicUrl,
    ) {
        $this->bucket    = $bucket;
        $this->endpoint  = rtrim($endpoint, '/');
        $this->publicUrl = rtrim($publicUrl, '/');

        $this->client = new S3Client([
            'version'                 => 'latest',
            'region'                  => $region,
            'endpoint'                => $endpoint,
            'use_path_style_endpoint' => true,
            'credentials'             => [
                'key'    => $key,
                'secret' => $secret,
            ],
        ]);
    }

    // ----------------------------------------------------------------
    // Multipart upload (pro velké soubory)
    // ----------------------------------------------------------------

    public function createMultipartUpload(string $key, string $mimeType): string
    {
        $result = $this->client->createMultipartUpload([
            'Bucket'      => $this->bucket,
            'Key'         => $key,
            'ContentType' => $mimeType,
        ]);

        return $result['UploadId'];
    }

    /**
     * Nahraje jeden chunk a vrátí jeho ETag.
     *
     * @param resource|string $body
     */
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

    /**
     * Dokončí multipart upload.
     *
     * @param array<array{PartNumber: int, ETag: string}> $parts
     */
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

    // ----------------------------------------------------------------
    // Jednoduchý upload (malé soubory < 5 MB)
    // ----------------------------------------------------------------

    public function putObject(string $key, mixed $body, string $mimeType): void
    {
        $this->client->putObject([
            'Bucket'      => $this->bucket,
            'Key'         => $key,
            'Body'        => $body,
            'ContentType' => $mimeType,
        ]);
    }

    // ----------------------------------------------------------------
    // Presigned URL pro stahování (platná 1 hodinu)
    // ----------------------------------------------------------------

    public function getPresignedUrl(string $key, int $expiresInSeconds = 3600): string
    {
        $cmd = $this->client->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key'    => $key,
        ]);

        $request = $this->client->createPresignedRequest($cmd, "+{$expiresInSeconds} seconds");

        $url = (string) $request->getUri();

        // Presigned URL obsahuje interní Docker hostname (např. http://minio:9000).
        // Nahradíme ho veřejnou URL aby odkaz fungoval v prohlížeči.
        if ($this->endpoint !== $this->publicUrl) {
            $url = str_replace($this->endpoint, $this->publicUrl, $url);
        }

        return $url;
    }

    // ----------------------------------------------------------------
    // Mazání
    // ----------------------------------------------------------------

    public function delete(string $key): void
    {
        $this->client->deleteObject([
            'Bucket' => $this->bucket,
            'Key'    => $key,
        ]);
    }

    // ----------------------------------------------------------------
    // Bucket bootstrap (vytvoří bucket pokud neexistuje)
    // ----------------------------------------------------------------

    public function ensureBucketExists(): void
    {
        if (!$this->client->doesBucketExist($this->bucket)) {
            $this->client->createBucket(['Bucket' => $this->bucket]);
        }
    }

    /**
     * Vrátí obsah souboru jako PHP stream (resource).
     *
     * Pokud jsou zadány $start / $end, použije se HTTP Range požadavek na S3
     * a vrátí pouze požadovaný úsek (pro 206 Partial Content proxy).
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
