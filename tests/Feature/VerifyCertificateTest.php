<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseFee;
use App\Models\User;
use App\Services\CertificatePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VerifyCertificateTest extends TestCase
{
    use RefreshDatabase;

    private function issueCertificate(): array
    {
        Storage::fake('local');

        $admin = User::factory()->admin()->create();
        $course = Course::factory()->published()->create([
            'created_by' => $admin->id,
            'title' => 'Data Science Fundamentals',
        ]);
        CourseFee::factory()->application()->create(['course_id' => $course->id, 'amount' => 50000]);
        CourseFee::factory()->tuition()->create(['course_id' => $course->id, 'amount' => 800000]);
        CourseFee::factory()->certificate()->create(['course_id' => $course->id, 'amount' => 50000]);

        $user = User::factory()->learner()->create(['name' => 'Patience Auma']);

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

        return [$user, $issue->json('data')];
    }

    public function test_public_verify_page_confirms_an_authentic_certificate(): void
    {
        [, $data] = $this->issueCertificate();

        $this->get('/verify/'.$data['certificate_reference'])
            ->assertOk()
            ->assertSee('Verified Certificate')
            ->assertSee('Patience Auma')
            ->assertSee('Data Science Fundamentals')
            ->assertSee($data['certificate_reference'])
            ->assertSee('An Institution of Custospark Company Ltd');
    }

    public function test_public_verify_page_handles_unknown_reference(): void
    {
        $this->get('/verify/CSA-UNKNOWN-0000')
            ->assertOk()
            ->assertSee('Unable to Confirm')
            ->assertSee('CSA-UNKNOWN-0000')
            ->assertDontSee('Verified Certificate');
    }

    public function test_public_verify_pdf_is_streamed_inline(): void
    {
        [, $data] = $this->issueCertificate();

        $response = $this->get('/verify/'.$data['certificate_reference'].'/pdf');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('inline;', $response->headers->get('Content-Disposition') ?? '');
        $this->assertStringStartsWith('%PDF-', $response->streamedContent());
    }

    public function test_public_verify_pdf_404s_for_unknown_reference(): void
    {
        $this->get('/verify/CSA-UNKNOWN-0000/pdf')->assertNotFound();
    }

    public function test_public_json_registry_summary(): void
    {
        [, $data] = $this->issueCertificate();

        $this->getJson('/api/v1/public/certificates/'.$data['certificate_reference'])
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.certificate_reference', $data['certificate_reference'])
            ->assertJsonPath('data.learner_name', 'Patience Auma')
            ->assertJsonPath('data.course_title', 'Data Science Fundamentals')
            ->assertJsonPath('data.issuer', 'Custospark Academy')
            ->assertJsonPath('data.institution', 'An Institution of Custospark Company Ltd')
            ->assertJsonStructure([
                'data' => ['valid', 'certificate_reference', 'learner_name', 'course_title', 'issued_at', 'verify_pdf_url'],
            ]);
    }

    public function test_public_json_registry_404s_for_unknown_reference(): void
    {
        $this->getJson('/api/v1/public/certificates/CSA-UNKNOWN-0000')
            ->assertNotFound()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.certificate_reference', 'CSA-UNKNOWN-0000');
    }

    public function test_verify_url_is_configurable_per_environment(): void
    {
        Storage::fake('local');
        $certificate = Certificate::factory()->create(['certificate_reference' => 'CSA-CONFIG-1234']);

        config(['app.certificate_verify_url' => 'https://verify.example.com']);
        $this->assertSame('https://verify.example.com/verify/CSA-CONFIG-1234', app(CertificatePdfService::class)->verifyUrl($certificate));

        config(['app.certificate_verify_url' => '']);
        config(['app.url' => 'http://localhost:8000']);
        $this->assertSame('http://localhost:8000/verify/CSA-CONFIG-1234', app(CertificatePdfService::class)->verifyUrl($certificate));
    }
}