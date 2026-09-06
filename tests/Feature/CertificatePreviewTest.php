<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\User;
use App\Services\CertificatePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * The certificate preview must never be usable as a certificate. These tests
 * pin the abuse controls: auth + published-only, no certificate record touched,
 * placeholder data only, diagonal PREVIEW watermark, no reference / QR /
 * verification, and non-cacheable inline delivery.
 */
class CertificatePreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_requires_authentication_and_a_published_course(): void
    {
        $admin = User::factory()->admin()->create();
        $published = Course::factory()->published()->create(['created_by' => $admin->id]);
        $draft = Course::factory()->draft()->create(['created_by' => $admin->id]);
        $learner = User::factory()->learner()->create();

        $this->asGuest()->getJson("/api/v1/courses/{$published->id}/certificate-preview")->assertUnauthorized();

        $this->actingAsUser($learner)->get("/api/v1/courses/{$draft->id}/certificate-preview")->assertNotFound();
        $this->actingAsUser($learner)->get('/api/v1/courses/999999/certificate-preview')->assertNotFound();

        $res = $this->actingAsUser($learner)->get("/api/v1/courses/{$published->id}/certificate-preview");
        $res->assertOk();
        $res->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('inline', (string) $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('certificate-preview-sample.pdf', (string) $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertSame('sample', $res->headers->get('X-Certificate-Preview'));
        $this->assertStringStartsWith('%PDF-', $res->streamedContent());

        // No certificate record is created or touched by previewing.
        $this->assertSame(0, Certificate::query()->count());
    }

    public function test_preview_markup_is_watermarked_and_carries_no_verifiable_data(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->published()->create([
            'created_by' => $admin->id,
            'title' => 'Data Science Fundamentals',
        ]);

        // Render the exact view/data the preview PDF is built from.
        $html = View::make('certificates.certificate', [
            'isPreview' => true,
            'certificate' => null,
            'student' => (object) ['name' => 'Sample Learner'],
            'course' => $course,
            'courseTitle' => $course->title,
            'courseLevel' => $course->level,
            'deliveryMode' => $course->delivery_mode,
            'reference' => 'PREVIEW-SAMPLE',
            'issuedAt' => null,
            'verifyUrl' => null,
            'logoDataUri' => null,
            'qrDataUri' => null,
        ])->render();

        // Watermark layers present: tiled diagonal lines + big diagonal PREVIEW + badge.
        $this->assertStringContainsString('class="wm"', $html);
        $this->assertGreaterThanOrEqual(6, substr_count($html, 'class="wm-line"'));
        $this->assertStringContainsString('class="wm-main">Preview<', $html);
        $this->assertStringContainsString('Sample preview &middot; not valid', $html);
        $this->assertStringContainsString('rotate(-24deg)', $html);

        // Placeholder data only, course title still shown so it is a useful preview.
        $this->assertStringContainsString('Sample Learner', $html);
        $this->assertStringContainsString('Data Science Fundamentals', $html);
        $this->assertStringContainsString('<div class="value">Sample</div>', $html);

        // Nothing that could be used to verify or pass off the document.
        $this->assertStringContainsString('No verification code', $html);
        $this->assertStringContainsString('has not been issued', $html);
        $this->assertStringNotContainsString('Scan the QR code', $html);
        $this->assertStringNotContainsString('Scan to verify', $html);
        $this->assertStringNotContainsString('/verify/', $html);
        $this->assertStringNotContainsString('CSA-', $html);
    }

    public function test_real_certificate_render_has_no_watermark(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->published()->create(['created_by' => $admin->id]);
        $learner = User::factory()->learner()->create();

        $html = View::make('certificates.certificate', [
            'isPreview' => false,
            'certificate' => null,
            'student' => $learner,
            'course' => $course,
            'courseTitle' => $course->title,
            'courseLevel' => $course->level,
            'deliveryMode' => $course->delivery_mode,
            'reference' => 'CSA-TEST-1-ABCD',
            'issuedAt' => now(),
            'verifyUrl' => 'http://localhost:8000/verify/CSA-TEST-1-ABCD',
            'logoDataUri' => null,
            'qrDataUri' => null,
        ])->render();

        $this->assertStringNotContainsString('class="wm"', $html);
        $this->assertStringNotContainsString('class="wm-main"', $html);
        $this->assertStringNotContainsString('class="wm-badge"', $html);
        $this->assertStringNotContainsString(' class="sheet is-preview"', $html);
        $this->assertStringNotContainsString('Sample preview', $html);
        $this->assertStringContainsString('CSA-TEST-1-ABCD', $html);
    }

    public function test_preview_service_renders_a_pdf_from_course_only(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->published()->create(['created_by' => $admin->id]);

        $bytes = app(CertificatePdfService::class)->renderPreviewPdf($course);

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertSame(0, Certificate::query()->count());
    }
}
