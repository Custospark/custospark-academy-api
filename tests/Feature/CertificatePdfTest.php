<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseFee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CertificatePdfTest extends TestCase
{
    use RefreshDatabase;

    private function issueCertificate(): array
    {
        Storage::fake('local');

        config(['app.frontend_url' => 'https://academy.custospark.com']);

        $admin = User::factory()->admin()->create();
        $course = Course::factory()->published()->create(['created_by' => $admin->id]);
        CourseFee::factory()->application()->create(['course_id' => $course->id, 'amount' => 50000]);
        CourseFee::factory()->tuition()->create(['course_id' => $course->id, 'amount' => 800000]);
        CourseFee::factory()->certificate()->create(['course_id' => $course->id, 'amount' => 50000]);

        $user = User::factory()->learner()->create();

        $enrollmentId = $this->actingAsUser($user)
            ->postJson('/api/v1/enrollments', ['course_id' => $course->id])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsUser($user)->postJson("/api/v1/enrollments/{$enrollmentId}/pay/application")->assertOk();
        $this->actingAsUser($user)->postJson("/api/v1/enrollments/{$enrollmentId}/pay/tuition")->assertOk();
        $this->actingAsUser($user)->postJson("/api/v1/enrollments/{$enrollmentId}/complete")->assertOk();
        $this->actingAsUser($user)->postJson("/api/v1/enrollments/{$enrollmentId}/pay/certificate")->assertOk();

        $issue = $this->actingAsUser($user)
            ->postJson("/api/v1/enrollments/{$enrollmentId}/certificate")
            ->assertCreated();

        return [$user, $issue->json('data'), $enrollmentId];
    }

    public function test_issued_certificate_renders_pdf_and_registers_preview_download_urls(): void
    {
        [$user, $data] = $this->issueCertificate();

        $shipped = Certificate::firstOrFail();
        $this->assertSame($shipped->pdf_path, $data['pdf_path']);

        $this->assertSame("/api/v1/certificates/{$data['id']}/pdf", $data['pdf_url']);
        $this->assertSame("/api/v1/certificates/{$data['id']}/download", $data['download_url']);

        Storage::disk('local')->assertExists($shipped->pdf_path);

        $preview = $this->actingAsUser($user)->get($data['pdf_url']);
        $preview->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $preview->streamedContent());

        $download = $this->actingAsUser($user)->get($data['download_url']);
        $download->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment;', $download->headers->get('Content-Disposition') ?? '');
        $this->assertStringStartsWith('%PDF-', $download->streamedContent());
    }

    public function test_show_serializes_certificate_with_qr_ready_fields(): void
    {
        [$user, $data] = $this->issueCertificate();

        $this->actingAsUser($user)->getJson("/api/v1/certificates/{$data['id']}")
            ->assertOk()
            ->assertJsonPath('data.certificate_reference', $data['certificate_reference'])
            ->assertJsonPath('data.pdf_url', $data['pdf_url'])
            ->assertJsonPath('data.download_url', $data['download_url']);
    }

    public function test_learner_cannot_preview_another_users_certificate(): void
    {
        [, $data] = $this->issueCertificate();
        $other = User::factory()->learner()->create();

        $this->actingAsUser($other)->get($data['pdf_url'])->assertStatus(403);
        $this->actingAsUser($other)->get($data['download_url'])->assertStatus(403);
    }

    public function test_issuing_a_certificate_emails_the_learner_the_pdf(): void
    {
        Mail::fake();

        [$learner, $data] = $this->issueCertificate();

        Mail::assertSent(\App\Mail\StandardEmail::class, function (\App\Mail\StandardEmail $mail) use ($learner, $data) {
            return $mail->hasTo($learner->email)
                && $mail->fileAttachments !== []
                && $mail->fileAttachments[0]['name'] === $this->attachmentName($data);
        });
    }

    private function attachmentName(array $data): string
    {
        $reference = $data['certificate_reference'];

        return 'certificate-'.preg_replace('/[^A-Za-z0-9_-]/', '-', $reference).'.pdf';
    }
}