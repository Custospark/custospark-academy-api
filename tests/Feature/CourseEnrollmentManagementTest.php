<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseEnrollmentManagementTest extends TestCase
{
    use RefreshDatabase;

    private function enroll(Course $course, string $status, array $paidFees = [], bool $certificate = false): Enrollment
    {
        $learner = User::factory()->learner()->create();
        $enrollment = Enrollment::query()->create([
            'course_id' => $course->id,
            'user_id' => $learner->id,
            'status' => $status,
            'applied_at' => now(),
        ]);

        foreach ($paidFees as $feeType) {
            Payment::query()->create([
                'enrollment_id' => $enrollment->id,
                'user_id' => $learner->id,
                'fee_type' => $feeType,
                'amount' => 1000,
                'currency' => 'UGX',
                'status' => Payment::STATUS_PAID,
                'method' => Payment::METHOD_MANUAL,
                'reference' => 'PAY-'.$enrollment->id.'-'.$feeType,
                'paid_at' => now(),
            ]);
        }

        if ($certificate) {
            Certificate::query()->create([
                'enrollment_id' => $enrollment->id,
                'user_id' => $learner->id,
                'course_id' => $course->id,
                'certificate_reference' => 'CSA-TEST-'.$enrollment->id,
                'issued_at' => now(),
            ]);
        }

        return $enrollment;
    }

    public function test_management_listing_carries_enrollment_summary_counts(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->published()->create(['created_by' => $admin->id, 'title' => 'Data Science Fundamentals']);

        $this->enroll($course, Enrollment::STATUS_APPLIED);
        $this->enroll($course, Enrollment::STATUS_ADMITTED, ['application']);
        $this->enroll($course, Enrollment::STATUS_IN_PROGRESS, ['application', 'tuition']);
        $this->enroll($course, Enrollment::STATUS_CERTIFIED, ['application', 'tuition', 'certificate'], certificate: true);
        $this->enroll($course, Enrollment::STATUS_REJECTED);

        $res = $this->actingAsUser($admin)->getJson('/api/v1/admin/courses');
        $res->assertOk();

        $row = collect($res->json('data'))->firstWhere('id', $course->id);
        $this->assertNotNull($row);
        $this->assertSame(4, $row['enrollment_summary']['enrolled']);
        $this->assertSame(1, $row['enrollment_summary']['pending_review']);
        $this->assertSame(1, $row['enrollment_summary']['admitted']);
        $this->assertSame(2, $row['enrollment_summary']['tuition_paid']);
        $this->assertSame(1, $row['enrollment_summary']['in_progress']);
        $this->assertSame(1, $row['enrollment_summary']['completed']);
        $this->assertSame(1, $row['enrollment_summary']['certified']);
        $this->assertSame(1, $row['enrollment_summary']['certificates_issued']);
        $this->assertSame(1, $row['enrollment_summary']['rejected']);
    }

    public function test_instructors_only_see_their_own_courses_and_enrollments_while_admins_see_all(): void
    {
        $admin = User::factory()->admin()->create();
        $instructorA = User::factory()->instructor()->create();
        $instructorB = User::factory()->instructor()->create();

        $courseA = Course::factory()->published()->create(['created_by' => $instructorA->id, 'title' => 'Course A']);
        $courseB = Course::factory()->draft()->create(['created_by' => $instructorB->id, 'title' => 'Course B']);

        $this->enroll($courseA, Enrollment::STATUS_IN_PROGRESS, ['application', 'tuition']);
        $this->enroll($courseB, Enrollment::STATUS_APPLIED);

        // Course management scoping
        $a = $this->actingAsUser($instructorA)->getJson('/api/v1/admin/courses');
        $a->assertOk();
        $this->assertSame(['Course A'], collect($a->json('data'))->pluck('title')->all());

        $all = $this->actingAsUser($admin)->getJson('/api/v1/admin/courses');
        $all->assertOk();
        $this->assertEqualsCanonicalizing(['Course A', 'Course B'], collect($all->json('data'))->pluck('title')->all());

        // Enrollment listing scoping
        $enrA = $this->actingAsUser($instructorA)->getJson('/api/v1/admin/enrollments');
        $enrA->assertOk();
        $this->assertCount(1, $enrA->json('data'));
        $this->assertSame('Course A', $enrA->json('data.0.course_title'));
        $this->assertTrue($enrA->json('data.0.has_paid_tuition'));
        $this->assertNotNull($enrA->json('data.0.user_email'));

        $enrAll = $this->actingAsUser($admin)->getJson('/api/v1/admin/enrollments');
        $enrAll->assertOk();
        $this->assertCount(2, $enrAll->json('data'));

        // Filters: by course and by status
        $byCourse = $this->actingAsUser($admin)->getJson('/api/v1/admin/enrollments?course_id='.$courseB->id);
        $this->assertCount(1, $byCourse->json('data'));
        $this->assertSame('Course B', $byCourse->json('data.0.course_title'));

        $byStatus = $this->actingAsUser($admin)->getJson('/api/v1/admin/enrollments?status=in_progress');
        $this->assertCount(1, $byStatus->json('data'));

        // Learners are locked out of staff views
        $learner = User::factory()->learner()->create();
        $this->actingAsUser($learner)->getJson('/api/v1/admin/enrollments')->assertForbidden();
        $this->actingAsUser($learner)->getJson('/api/v1/admin/courses')->assertForbidden();
    }

    public function test_instructor_cannot_admit_enrollment_on_someone_elses_course(): void
    {
        $instructorA = User::factory()->instructor()->create();
        $instructorB = User::factory()->instructor()->create();
        $courseB = Course::factory()->published()->create(['created_by' => $instructorB->id]);

        $enrollment = $this->enroll($courseB, Enrollment::STATUS_APPLICATION_FEE_PAID, ['application']);

        $this->actingAsUser($instructorA)->postJson("/api/v1/admin/enrollments/{$enrollment->id}/admit")
            ->assertForbidden();

        // Owning instructor can admit. With no tuition fee configured the
        // admitted learner is auto-advanced (waived tuition) to tuition_paid.
        $res = $this->actingAsUser($instructorB)->postJson("/api/v1/admin/enrollments/{$enrollment->id}/admit");
        $res->assertOk();
        $this->assertContains($res->json('data.status'), ['admitted', 'tuition_paid']);
        $this->assertNotNull($res->json('data.admitted_at'));
    }
}
