<?php

namespace App\Service;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Služba pro generování QR kódů pro krátké linky
 */
class QRCodeService
{
    private const QR_CODE_SIZE = 300;
    private const MARGIN = 10;

    /**
     * Vygeneruje QR kód pro danou URL a vrátí jej jako Response (PNG)
     */
    public function generateQRCodeResponse(string $url): Response
    {
        try {
            $qrCode = Builder::create()
                ->writer(new PngWriter())
                ->writerOptions([])
                ->data($url)
                ->encoding(new Encoding('UTF-8'))
                ->errorCorrectionLevel(ErrorCorrectionLevel::High)
                ->size(self::QR_CODE_SIZE)
                ->margin(self::MARGIN)
                ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
                ->build();

            $image = $qrCode->getImage();

            return new Response(
                $image->getString(),
                200,
                [
                    'Content-Type' => 'image/png',
                    'Content-Disposition' => 'inline; filename="qrcode.png"',
                    'Cache-Control' => 'public, max-age=3600',
                ]
            );
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to generate QR code: ' . $e->getMessage());
        }
    }

    /**
     * Vygeneruje QR kód a vrátí jej jako Base64 string (pro vložení do HTML)
     */
    public function generateQRCodeBase64(string $url): string
    {
        try {
            $qrCode = Builder::create()
                ->writer(new PngWriter())
                ->writerOptions([])
                ->data($url)
                ->encoding(new Encoding('UTF-8'))
                ->errorCorrectionLevel(ErrorCorrectionLevel::High)
                ->size(self::QR_CODE_SIZE)
                ->margin(self::MARGIN)
                ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
                ->build();

            $image = $qrCode->getImage();
            $imageData = $image->getString();

            return 'data:image/png;base64,' . base64_encode($imageData);
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to generate QR code: ' . $e->getMessage());
        }
    }

    /**
     * Vygeneruje QR kód a uloží jej do souboru
     */
    public function generateQRCodeFile(string $url, string $filepath): void
    {
        try {
            $qrCode = Builder::create()
                ->writer(new PngWriter())
                ->writerOptions([])
                ->data($url)
                ->encoding(new Encoding('UTF-8'))
                ->errorCorrectionLevel(ErrorCorrectionLevel::High)
                ->size(self::QR_CODE_SIZE)
                ->margin(self::MARGIN)
                ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
                ->build();

            $image = $qrCode->getImage();
            file_put_contents($filepath, $image->getString());
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to generate QR code file: ' . $e->getMessage());
        }
    }
}
