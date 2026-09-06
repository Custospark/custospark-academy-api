<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Qr\QrCodeService;
use PHPUnit\Framework\TestCase;

class QrCodeServiceTest extends TestCase
{
    public function test_it_renders_a_scannable_png_data_uri(): void
    {
        $service = new QrCodeService();

        $bytes = $service->pngBytes('https://academy.custospark.com/certificates/CS-TEST-0001');

        $this->assertStringStartsWith("\x89PNG", $bytes);
        $this->assertGreaterThan(200, strlen($bytes));

        $image = imagecreatefromstring($bytes);
        $this->assertNotFalse($image);
        $this->assertGreaterThan(100, imagesx($image));
        $this->assertGreaterThan(100, imagesy($image));
        imagedestroy($image);
    }

    public function test_it_is_deterministic(): void
    {
        $service = new QrCodeService();
        $payload = 'https://academy.custospark.com/certificates/CS-TEST-0002';

        $this->assertSame($service->pngBytes($payload), $service->pngBytes($payload));
    }

    public function test_it_exposes_a_data_uri(): void
    {
        $service = new QrCodeService();

        $uri = $service->dataUri('CS-TEST-0003');

        $this->assertStringStartsWith('data:image/png;base64,', $uri);
        $this->assertGreaterThan(64, strlen($uri));
    }

    public function test_it_rejects_empty_payloads(): void
    {
        $this->expectExceptionMessage('QR payload must not be empty.');

        (new QrCodeService())->pngBytes('');
    }
}