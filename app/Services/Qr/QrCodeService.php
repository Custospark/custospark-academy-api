<?php

declare(strict_types=1);

namespace App\Services\Qr;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Generates scannable QR codes as PNG bytes or data URIs using the vendored,
 * MIT-licensed single-file encoder (see app/Services/Qr/barcode.php). No
 * external services or GD-free network calls - fully offline determinism.
 *
 * The payload is embedded at a fixed module scale so previews, downloads and
 * printed certificates all render crisply and identically.
 *
 * Branded codes overlay the Academy logo at the centre with a white cushion
 * and softly rounded outer corners. High error correction (qrh) keeps the
 * code scannable despite the centre occlusion.
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

    /**
     * Branded verification QR: logo centred at ~22% size on a white cushion,
     * outer corners rounded. Uses qrh error correction so the centre
     * occlusion stays scannable on any smartphone camera.
     */
    public function brandedPngBytes(string $data, int $scale = 10, float $logoRatio = 0.22): string
    {
        $qr = $this->generator->render_image('qrh', $data, ['sf' => $scale, 'md' => 1.0]);
        if ($qr === null || $qr === false) {
            throw new RuntimeException('QR code could not be rendered.');
        }

        $w = imagesx($qr);
        $h = imagesy($qr);

        // Normalise to truecolour with alpha so corner masking is clean.
        $canvas = imagecreatetruecolor($w, $h);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparent);
        imagecopy($canvas, $qr, 0, 0, 0, 0, $w, $h);
        imagedestroy($qr);
        $qr = $canvas;

        $this->applyRoundedCorners($qr, (int) round(min($w, $h) * 0.12));

        $logoBytes = $this->logoBytes();
        if ($logoBytes !== null && ($logo = @imagecreatefromstring($logoBytes)) !== false) {
            $lw = imagesx($logo);
            $lh = imagesy($logo);
            $target = (int) round(min($w, $h) * $logoRatio);
            $aspect = $lw > 0 && $lh > 0 ? $lw / $lh : 1.0;
            $tw = $aspect >= 1.0 ? $target : (int) round($target * $aspect);
            $th = $aspect >= 1.0 ? (int) round($target / $aspect) : $target;

            $resized = imagecreatetruecolor($tw, $th);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $clear = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            imagefill($resized, 0, 0, $clear);
            imagecopyresampled($resized, $logo, 0, 0, 0, 0, $tw, $th, $lw, $lh);
            imagedestroy($logo);
            $this->applyRoundedCorners($resized, (int) round(min($tw, $th) * 0.18));

            // White cushion slightly larger than the logo for contrast.
            $pad = (int) round(min($w, $h) * 0.035);
            $cw = $tw + $pad * 2;
            $ch = $th + $pad * 2;
            $cx = (int) round(($w - $cw) / 2);
            $cy = (int) round(($h - $ch) / 2);
            $this->fillRoundedRect($qr, $cx, $cy, $cw, $ch, $pad, [255, 255, 255]);
            imagecopy($qr, $resized, $cx + $pad, $cy + $pad, 0, 0, $tw, $th);
            imagedestroy($resized);
        }

        ob_start();
        try {
            imagepng($qr);
            $bytes = (string) ob_get_clean();
        } catch (\Throwable $e) {
            if (ob_get_length() !== false) {
                ob_end_clean();
            }
            imagedestroy($qr);
            throw new RuntimeException('QR PNG encoding failed: '.$e->getMessage(), 0, $e);
        }
        imagedestroy($qr);

        return $bytes;
    }

    public function brandedDataUri(string $data, int $scale = 10, float $logoRatio = 0.22): string
    {
        return 'data:image/png;base64,'.base64_encode($this->brandedPngBytes($data, $scale, $logoRatio));
    }

    /** Academy logo bytes: operator override in storage, else bundled fallback. */
    protected function logoBytes(): ?string
    {
        try {
            $stored = Storage::disk('local')->get('brand/custospark_academy_logo.png');
            if (is_string($stored) && $stored !== '') {
                return $stored;
            }
        } catch (\Throwable) {
            // Fall through to the bundled asset.
        }

        $bundled = base_path('resources/brand/custospark_academy_logo.png');
        if (is_file($bundled)) {
            $bytes = @file_get_contents($bundled);
            if (is_string($bytes) && $bytes !== '') {
                return $bytes;
            }
        }

        return null;
    }

    /** Mask pixels outside each corner's quarter-circle as transparent. */
    protected function applyRoundedCorners(\GdImage $image, int $radius): void
    {
        if ($radius <= 0) {
            return;
        }
        $w = imagesx($image);
        $h = imagesy($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);

        foreach ([[0, 0, 1, 1], [$w, 0, -1, 1], [0, $h, 1, -1], [$w, $h, -1, -1]] as [$cx, $cy, $sx, $sy]) {
            for ($i = 0; $i <= $radius; $i++) {
                for ($j = 0; $j <= $radius; $j++) {
                    if (($i - $radius) ** 2 + ($j - $radius) ** 2 > $radius ** 2) {
                        imagesetpixel($image, $cx + ($i * $sx), $cy + ($j * $sy), $transparent);
                    }
                }
            }
        }
    }

    /** Filled white (or given RGB) rounded rectangle for the logo cushion. */
    protected function fillRoundedRect(\GdImage $image, int $x, int $y, int $w, int $h, int $radius, array $rgb): void
    {
        [$r, $g, $b] = $rgb;
        $color = imagecolorallocate($image, $r, $g, $b);
        $radius = max(0, min($radius, (int) floor(min($w, $h) / 2)));

        imagefilledrectangle($image, $x + $radius, $y, $x + $w - $radius, $y + $h, $color);
        imagefilledrectangle($image, $x, $y + $radius, $x + $w, $y + $h - $radius, $color);
        imagefilledellipse($image, $x + $radius, $y + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x + $w - $radius, $y + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x + $radius, $y + $h - $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x + $w - $radius, $y + $h - $radius, $radius * 2, $radius * 2, $color);
    }
}