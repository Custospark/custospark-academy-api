<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\StandardEmail;
use App\Models\Course;
use App\Models\CourseFee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Every learner journey step sends exactly one personalized professional
 * email (welcome + each enrollment status). Delivery failures must never
 * break the underlying flow (covered by the service's internal guard).
 */
class EnrollmentNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function subjects(): array
    {
        return collect(Mail::sent(StandardEmail::class))->map(
            fn ($mailable) => $mailable->title
        )->all();
    }

    /** Subjects sent since $count mails were on record. */
    private function newSubjects(int $count): array
    {
        return array_slice($this->subjects(), $count);
    }

    public function test_registration_and_full_journey_each_send_one_email(): void
    {
        Mail::fake();

        $admin = User::factory()->admin()->create();
        $course = Course::factory()->create([
            'created_by' => $admin->id,
            'title' => 'Full Stack Web Development',
            'status' => Course::STATUS_PUBLISHED,
            'delivery_mode' => Course::DELIVERY_SELF_PACED,
            'is_self_paced' => true,
        ]);
        CourseFee::factory()->create(['course_id' => $course->id, 'fee_type' => 'application', 'amount' => 25000]);
        CourseFee::factory()->create(['course_id' => $course->id, 'fee_type' => 'tuition', 'amount' => 100000]);
        CourseFee::factory()->create(['course_id' => $course->id, 'fee_type' => 'certificate', 'amount' => 150000]);

        // 1. Registration -> welcome.
        $learner = $this->postJson('/api/v1/auth/register', [
            'name' => 'Grace Auma',
            'email' => 'grace@example.com',
            'phone' => '+256700000001',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertCreated()->json('data.user');
        Mail::assertSent(StandardEmail::class, 1);
        $this->assertSame('Welcome to Custospark Academy', $this->subjects()[0]);

        $asLearner = fn () => $this->actingAsUser(User::query()->find($learner['id']));

        // 2. Apply -> received.
        $enrollmentId = $asLearner()->postJson('/api/v1/enrollments', ['course_id' => $course->id])
            ->assertCreated()->json('data.id');
        Mail::assertSent(StandardEmail::class, 2);
        $this->assertStringStartsWith('Application received', $this->subjects()[1]);

        // 3. Pay application -> fee confirmed + admitted (+ payment receipt).
        $before = count($this->subjects());
        $asLearner()->postJson("/api/v1/enrollments/{$enrollmentId}/pay/application")->assertOk();
        $fresh = $this->newSubjects($before);
        $this->assertContains('Application fee confirmed - Full Stack Web Development', $fresh);
        $this->assertContains('Admitted - Full Stack Web Development', $fresh);

        // 4. Pay tuition -> start learning.
        $before = count($this->subjects());
        $asLearner()->postJson("/api/v1/enrollments/{$enrollmentId}/pay/tuition")->assertOk();
        $this->assertContains('Tuition settled - start learning Full Stack Web Development', $this->newSubjects($before));

        // 5. Complete (empty course trivially completes) -> claim certificate.
        $asLearner()->postJson("/api/v1/enrollments/{$enrollmentId}/complete")->assertOk();
        $this->assertContains(
            'Course completed - claim your certificate',
            $this->newSubjects(count($this->subjects()) - 1)
        );

        // 6. Pay certificate -> ready; issue -> certified.
        $asLearner()->postJson("/api/v1/enrollments/{$enrollmentId}/pay/certificate")->assertOk();
        $before = count($this->subjects());
        $asLearner()->postJson("/api/v1/enrollments/{$enrollmentId}/certificate")->assertCreated();
        $fresh = $this->newSubjects($before);
        $this->assertTrue(collect($fresh)->contains(fn ($s) => str_starts_with($s, 'Certified')));

        // Every mail went to the learner, personally addressed.
        foreach (Mail::sent(StandardEmail::class) as $mailable) {
            $this->assertStringContainsString('Grace', $mailable->mailBody);
        }
        Mail::assertSent(
            StandardEmail::class,
            fn ($m) => $m->hasTo('grace@example.com')
        );

        // Every journey mail carries Oscar's personal signature (payment
        // receipts intentionally do not - they stay transactional).
        $journey = collect(Mail::sent(StandardEmail::class))->filter(
            fn ($m) => ! str_contains(strtolower($m->title), 'receipt')
        );
        $this->assertNotEmpty($journey);
        foreach ($journey as $mailable) {
            $this->assertStringContainsString('Opiyo Oscar', $mailable->signature ?? '');
            $this->assertStringContainsString('Founder &amp; CEO', $mailable->signature ?? '');
        }
    }

    public function test_reject_and_cancel_notify_with_reapply_paths(): void
    {
        Mail::fake();

        $admin = User::factory()->admin()->create();
        $course = Course::factory()->published()->create(['created_by' => $admin->id]);
        CourseFee::factory()->create(['course_id' => $course->id, 'fee_type' => 'application', 'amount' => 25000]);
        $learner = User::factory()->learner()->create(['name' => 'Brian Okot']);

        $enrollmentId = $this->actingAsUser($learner)
            ->postJson('/api/v1/enrollments', ['course_id' => $course->id])
            ->assertCreated()->json('data.id');

        $this->actingAsUser($admin)
            ->postJson("/api/v1/admin/enrollments/{$enrollmentId}/reject", ['note' => 'Cohort full.'])
            ->assertOk();
        Mail::assertSent(
            StandardEmail::class,
            fn ($m) => str_contains($m->title, 'Update on your application')
                && str_contains($m->mailBody, 'Brian')
        );
    }
}
