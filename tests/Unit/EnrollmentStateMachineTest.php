<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Course;
use App\Models\CourseFee;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\EnrollmentStateMachineService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentStateMachineTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrollment_follows_payment_gated_lifecycle(): void
    {
        $user = User::factory()->learner()->create();
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->published()->create(['created_by' => $admin->id]);
        CourseFee::factory()->application()->create(['course_id' => $course->id]);
        CourseFee::factory()->tuition()->create(['course_id' => $course->id]);
        CourseFee::factory()->certificate()->create(['course_id' => $course->id]);

        $enrollments = app(EnrollmentStateMachineService::class);

        $enrollment = $enrollments->apply((int) $course->id, (int) $user->id);
        $this->assertSame(Enrollment::STATUS_APPLIED, $enrollment->status);

        // Cannot admit before paying application fee.
        $this->expectException(\RuntimeException::class);
        $enrollments->admit($enrollment);
    }

    public function test_cannot_transition_to_an_unallowed_status(): void
    {
        $user = User::factory()->learner()->create();
        $course = Course::factory()->published()->create();
        $enrollments = app(EnrollmentStateMachineService::class);

        $enrollment = $enrollments->apply((int) $course->id, (int) $user->id);

        $this->expectException(\RuntimeException::class);
        $enrollments->complete($enrollment);
    }

    public function test_duplicate_application_is_rejected(): void
    {
        $user = User::factory()->learner()->create();
        $course = Course::factory()->published()->create();
        $enrollments = app(EnrollmentStateMachineService::class);

        $enrollments->apply((int) $course->id, (int) $user->id);

        $this->expectException(\RuntimeException::class);
        $enrollments->apply((int) $course->id, (int) $user->id);
    }

    public function test_reject_only_allowed_for_applications(): void
    {
        $user = User::factory()->learner()->create();
        $course = Course::factory()->published()->create();
        $enrollments = app(EnrollmentStateMachineService::class);

        $enrollment = $enrollments->apply((int) $course->id, (int) $user->id);
        $rejected = $enrollments->reject($enrollment, 'not enough information');

        $this->assertSame(Enrollment::STATUS_REJECTED, $rejected->status);

        // Rejecting again should fail.
        $this->expectException(\RuntimeException::class);
        $enrollments->reject($rejected);
    }

    public function test_certified_enrollment_cannot_be_cancelled(): void
    {
        $enrollment = Enrollment::factory()->create(['status' => Enrollment::STATUS_CERTIFIED]);
        $enrollments = app(EnrollmentStateMachineService::class);

        $this->expectException(\RuntimeException::class);
        $enrollments->cancel($enrollment);
    }
}