<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Course;
use App\Models\CourseFee;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\User;
use App\Services\EnrollmentStateMachineService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_paying_application_fee_advances_enrollment(): void
    {
        $user = User::factory()->learner()->create();
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->published()->create(['created_by' => $admin->id]);
        CourseFee::factory()->application()->create(['course_id' => $course->id, 'amount' => 50000]);
        // Tuition is charged, so paying the app fee should stop at admitted.
        CourseFee::factory()->tuition()->create(['course_id' => $course->id, 'amount' => 800000]);

        $enrollments = app(EnrollmentStateMachineService::class);
        $payments = app(PaymentService::class);

        $enrollment = $enrollments->apply((int) $course->id, (int) $user->id);
        $payment = $payments->createForFee($enrollment, $user, CourseFee::FEE_APPLICATION);

        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertSame('50000.00', (string) $payment->amount);
        $this->assertSame('UGX', $payment->currency);

        $payments->markPaid($payment, 'INLINE-TEST');

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        // No human in the loop: paying the application fee auto-admits.
        $this->assertSame(Enrollment::STATUS_ADMITTED, $enrollment->fresh()->status);
        $this->assertTrue($enrollment->fresh()->hasPaidApplication());
    }

    public function test_paying_same_fee_twice_is_rejected(): void
    {
        $user = User::factory()->learner()->create();
        $course = Course::factory()->published()->create();
        CourseFee::factory()->application()->create(['course_id' => $course->id]);

        $enrollments = app(EnrollmentStateMachineService::class);
        $payments = app(PaymentService::class);

        $enrollment = $enrollments->apply((int) $course->id, (int) $user->id);
        $payments->createForFee($enrollment, $user, CourseFee::FEE_APPLICATION);
        $payments->markPaid($enrollment->payments()->first(), 'REF-1');

        $this->expectException(\RuntimeException::class);
        $payments->createForFee($enrollment->fresh(), $user, CourseFee::FEE_APPLICATION);
    }

    public function test_unconfigured_fee_throws(): void
    {
        $user = User::factory()->learner()->create();
        $course = Course::factory()->published()->create();
        $enrollments = app(EnrollmentStateMachineService::class);
        $payments = app(PaymentService::class);

        $enrollment = $enrollments->apply((int) $course->id, (int) $user->id);

        $this->expectException(\RuntimeException::class);
        $payments->createForFee($enrollment, $user, CourseFee::FEE_TUITION);
    }

    public function test_failed_payment_does_not_advance_enrollment(): void
    {
        $user = User::factory()->learner()->create();
        $course = Course::factory()->published()->create();
        CourseFee::factory()->application()->create(['course_id' => $course->id]);

        $enrollments = app(EnrollmentStateMachineService::class);
        $payments = app(PaymentService::class);

        $enrollment = $enrollments->apply((int) $course->id, (int) $user->id);
        $payment = $payments->createForFee($enrollment, $user, CourseFee::FEE_APPLICATION);
        $payments->markFailed($payment);

        $this->assertSame(Payment::STATUS_FAILED, $payment->fresh()->status);
        $this->assertSame(Enrollment::STATUS_APPLIED, $enrollment->fresh()->status);
        $this->assertCount(2, $payment->fresh()->journal); // created + failed
    }
}