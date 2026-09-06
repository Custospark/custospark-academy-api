<?php

declare(strict_types=1);

namespace App\Services\Qr;

use RuntimeException;

/**
 * Generates scannable QR codes as PNG bytes or data URIs using the vendored,
 * MIT-licensed single-file encoder (see app/Services/Qr/barcode.php). No
 * external services or GD-free network calls - fully offline determinism.
 *
 * The payload is embedded at a fixed module scale so previews, downloads and
 * printed certificates all render crisply and identically.
 */
class QrCodeService
{
    private barcode_generator $generator;

    public function __construct(?barcode_generator $generator = null)
    {
        $this->generator = $generator ?? new barcode_generator();
    }

    /**
     * Render a QR code as PNG bytes.
     *
     * @param  string  $data  payload (URLs recommended - keeps the code dense and scannable)
     * @param  string  $ecc   error correction level: qr, qrm, qrq or qrh
     */
    public function pngBytes(string $data, string $ecc = 'qrm', int $scale = 10): string
    {
        if ($data === '') {
            throw new RuntimeException('QR payload must not be empty.');
        }

        $image = $this->generator->render_image($ecc, $data, ['sf' => $scale, 'md' => 1.0]);

        if ($image === null || $image === false) {
            throw new RuntimeException('QR code could not be rendered.');
        }

        ob_start();
        try {
            \imagepng($image);
            $bytes = (string) ob_get_clean();
        } catch (\Throwable $e) {
            if (ob_get_length() !== false) {
                ob_end_clean();
            }
            \imagedestroy($image);
            throw new RuntimeException('QR PNG encoding failed: '.$e->getMessage(), 0, $e);
        }
        \imagedestroy($image);

        return $bytes;
    }

    public function dataUri(string $data, string $ecc = 'qrm', int $scale = 10): string
    {
        return 'data:image/png;base64,'.base64_encode($this->pngBytes($data, $ecc, $scale));
    }
}