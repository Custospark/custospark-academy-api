<?php

declare(strict_types=1);

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf as DomPdf;
use Illuminate\Support\Facades\Storage;

/**
 * Thin wrapper around barryvdh/laravel-dompdf shared by receipts and
 * certificates. Keeps the PDF plumbing in one place so blade views stay
 * declarative and consumers only pass view + data.
 */
class PdfService
{
    private const LOGO_PATH = 'brand/custospark_academy_logo.png';

    public function render(string $view, array $data, string $paper = 'a4', string $orientation = 'portrait'): string
    {
        return (string) DomPdf::loadView($view, $data)
            ->setPaper($paper, $orientation)
            ->output();
    }

    /**
     * Academy logo (sourced from the frontend app) as an embeddable PNG data
     * URI. Returns null when the asset is unavailable so documents degrade to
     * a text-only brand instead of breaking.
     */
    public function logoDataUri(): ?string
    {
        $bytes = Storage::disk('local')->get(self::LOGO_PATH);

        if ($bytes === null || $bytes === false) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($bytes);
    }

    /**
     * Logo with softly rounded corners, pre-rendered in PHP (GD) because
     * dompdf cannot reliably clip images. The corners are masked transparent
     * so the document keeps a crisp, trustworthy finish on any background.
     */
    public function roundedLogoDataUri(int $radiusPercent = 10): ?string
    {
        $bytes = Storage::disk('local')->get(self::LOGO_PATH);
        if ($bytes === null || $bytes === false) {
            return null;
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $radius = (int) round(min($w, $h) * $radiusPercent / 100);
        $transparent = imagecolorallocatealpha($src, 0, 0, 0, 127);

        imagealphablending($src, false);
        imagesavealpha($src, true);

        // Clear pixels outside each corner's quarter-circle (radial distance).
        $corners = [
            [0, 0, 1, 1],
            [$w, 0, -1, 1],
            [0, $h, 1, -1],
            [$w, $h, -1, -1],
        ];

        foreach ($corners as [$cx, $cy, $sx, $sy]) {
            foreach (range(0, $radius) as $i) {
                $x = $cx + ($i * $sx);
                foreach (range(0, $radius) as $j) {
                    $y = $cy + ($j * $sy);
                    if (($i - $radius) ** 2 + ($j - $radius) ** 2 > $radius ** 2) {
                        imagesetpixel($src, $x, $y, $transparent);
                    }
                }
            }
        }

        ob_start();
        imagepng($src);
        $out = (string) ob_get_clean();
        imagedestroy($src);

        return 'data:image/png;base64,'.base64_encode($out);
    }

    public function sanitisename(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '-', $value) ?: 'document';
    }

    public function filename(string ...$parts): string
    {
        return implode('-', array_map(fn (string $part) => $this->sanitisename($part), $parts));
    }
}