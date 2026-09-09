<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseFee;
use App\Models\Payment;
use App\Models\PaymentJournal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * End-to-end payment lifecycle: apply -> pay application -> admitted ->
 * pay tuition -> learn -> complete -> pay certificate -> certified.
 *
 * Runs against the bypass gateway (phpunit sets PESAPAL_BYPASS=true), so the
 * full HTTP flow executes with no external calls. Every transition is BOTH
 * asserted AND written to the log (tag [E2E-PAY]) so the payment/state-machine
 * story can be read back step by step - run with:
 *
 *   php artisan test --filter=PaymentLifecycleTest
 *   grep E2E-PAY storage/logs/laravel.log
 */
class PaymentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private int $step = 0;

    private function journey(string $message): void
    {
        $this->step++;
        $line = "[E2E-PAY][step {$this->step}] {$message}";
        Log::info($line);
        fwrite(STDOUT, $line.PHP_EOL);
    }

    private function paidSum(int $enrollmentId): float
    {
        return (float) Payment::query()
            ->where('enrollment_id', $enrollmentId)
            ->where('status', Payment::STATUS_PAID)
            ->sum('amount');
    }

    public function test_full_payment_lifecycle_from_apply_to_certified(): void
    {
        // ---- Setup: self-paced course, three priced fees, one lesson --------
        $admin = User::factory()->admin()->create();
        $learner = User::factory()->learner()->create();
        $stranger = User::factory()->learner()->create();

        $course = Course::factory()->create([
            'created_by' => $admin->id,
            'status' => Course::STATUS_PUBLISHED,
            'delivery_mode' => Course::DELIVERY_SELF_PACED,
            'is_self_paced' => true,
        ]);
        CourseFee::factory()->create(['course_id' => $course->id, 'fee_type' => 'application', 'amount' => 25000]);
        CourseFee::factory()->create(['course_id' => $course->id, 'fee_type' => 'tuition', 'amount' => 100000]);
        CourseFee::factory()->create(['course_id' => $course->id, 'fee_type' => 'certificate', 'amount' => 150000]);

        $section = $this->actingAsUser($admin)
            ->postJson("/api/v1/admin/courses/{$course->id}/sections", ['title' => 'Module 1'])
            ->assertCreated()->json('data');
        $lesson = $this->actingAsUser($admin)
            ->postJson("/api/v1/admin/courses/{$course->id}/lessons", [
                'title' => 'Welcome',
                'content_type' => 'text',
                'content' => 'Hello learner.',
                'section_id' => $section['id'],
                'is_free_preview' => true,
            ])
            ->assertCreated()->json('data');
        $this->journey("course {$course->id} ready: self-paced, fees 25k/100k/150k, 1 published lesson");

        // ---- 1. Apply -------------------------------------------------------
        $enrollmentId = $this->actingAsUser($learner)
            ->postJson('/api/v1/enrollments', ['course_id' => $course->id])
            ->assertCreated()->json('data.id');
        $this->assertDatabaseHas('enrollments', ['id' => $enrollmentId, 'status' => 'applied']);
        $this->journey("learner applied (enrollment {$enrollmentId}) -> status=applied, paid=0");

        // ---- 2. Material access rules ---------------------------------------
        $this->actingAsUser($learner)
            ->getJson("/api/v1/courses/{$course->id}/content")
            ->assertOk();
        $this->journey('enrolled learner (unpaid) CAN read course content - enrollment grants read access');
        $this->actingAsUser($stranger)
            ->getJson("/api/v1/courses/{$course->id}/content")
            ->assertForbidden();
        $this->journey('stranger (not enrolled) gets 403 on course content');

        // ---- 3. Admit is blocked until the application fee is paid -----------
        $this->actingAsUser($admin)
            ->postJson("/api/v1/admin/enrollments/{$enrollmentId}/admit")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Application fee must be paid before admission.');
        $this->journey('admit before payment -> 422 (business rule holds)');

        // ---- 4. Pay application fee (bypass auto-approves in tests) ----------
        $pay = $this->actingAsUser($learner)
            ->postJson("/api/v1/enrollments/{$enrollmentId}/pay/application")
            ->assertOk()->json('data');
        $this->assertSame('paid', $pay['payment']['status']);
        $this->assertSame('admitted', $pay['enrollment']['status']);
        $this->assertSame(25000.0, $this->paidSum($enrollmentId));
        $this->assertSame(2, PaymentJournal::query()->where('payment_id', $pay['payment']['id'])->count());
        $this->journey("application fee paid (25k, journal created+approved) -> auto-admitted; paid total=25000");

        // ---- 5. Completion is blocked until tuition is paid ------------------
        $this->actingAsUser($learner)
            ->postJson("/api/v1/enrollments/{$enrollmentId}/complete")
            ->assertStatus(422);
        $this->journey('complete before tuition -> 422 (no skipping the money states)');

        // ---- 6. Pay tuition, learn, complete ---------------------------------
        $this->actingAsUser($learner)
            ->postJson("/api/v1/enrollments/{$enrollmentId}/pay/tuition")
            ->assertOk();
        $this->assertSame(125000.0, $this->paidSum($enrollmentId));
        $this->journey('tuition paid (100k) -> status=tuition_paid; paid total=125000');

        $this->actingAsUser($learner)
            ->postJson("/api/v1/courses/{$course->id}/lessons/{$lesson['id']}/progress", ['status' => 'completed'])
            ->assertOk();
        $this->journey('lesson marked completed (learning activity works while enrolled)');

        // Self-paced + manifest satisfied -> the progress hook auto-completes.
        $this->assertSame(
            'completed',
            $this->actingAsUser($learner)->getJson('/api/v1/enrollments/mine')->json('data.0.status')
        );
        $this->journey('manifest satisfied (1/1 lessons) -> auto-completed by the progress hook');

        // Completing twice is refused instead of double-firing.
        $this->actingAsUser($learner)
            ->postJson("/api/v1/enrollments/{$enrollmentId}/complete")
            ->assertStatus(422);
        $this->journey('second complete call -> 422 (no double completion)');

        // ---- 7. Pay certificate, get certified --------------------------------
        $this->actingAsUser($learner)
            ->postJson("/api/v1/enrollments/{$enrollmentId}/pay/certificate")
            ->assertOk();
        $this->assertSame(275000.0, $this->paidSum($enrollmentId));
        $this->journey('certificate fee paid (150k) -> status=certification; paid total=275000');

        $cert = $this->actingAsUser($learner)
            ->postJson("/api/v1/enrollments/{$enrollmentId}/certificate")
            ->assertCreated()->json('data');
        $this->assertNotEmpty($cert['certificate_reference']);
        $this->journey("certificate issued ({$cert['certificate_reference']}) -> status=certified");

        $this->actingAsUser($learner)
            ->getJson("/api/v1/certificates/{$cert['id']}/pdf")
            ->assertOk();
        $this->actingAsUser($stranger)
            ->getJson("/api/v1/certificates/{$cert['id']}/pdf")
            ->assertForbidden();
        $this->journey('owner can view certificate PDF; stranger gets 403');

        $this->getJson("/api/v1/public/certificates/{$cert['certificate_reference']}")
            ->assertOk()
            ->assertJsonPath('data.valid', true);
        $this->journey('public registry confirms the certificate (valid=true)');

        // ---- 8. Money guards: no double charging ------------------------------
        $this->actingAsUser($learner)
            ->postJson("/api/v1/enrollments/{$enrollmentId}/pay/application")
            ->assertStatus(422);
        $this->actingAsUser($learner)
            ->postJson("/api/v1/enrollments/{$enrollmentId}/pay/tuition")
            ->assertStatus(422);
        $this->actingAsUser($learner)
            ->postJson("/api/v1/enrollments/{$enrollmentId}/pay/certificate")
            ->assertStatus(422);
        $this->assertSame(275000.0, $this->paidSum($enrollmentId));
        $this->assertSame(3, Payment::query()->where('enrollment_id', $enrollmentId)->where('status', 'paid')->count());
        $this->journey('re-pay of any settled fee -> 422 already-paid; exactly 3 paid payments, total 275000');

        $this->journey('LIFECYCLE COMPLETE: applied -> admitted -> tuition_paid -> completed -> certification -> certified, 275000 collected, journal balanced');
    }
}
