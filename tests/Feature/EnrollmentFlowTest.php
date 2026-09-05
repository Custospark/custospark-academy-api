<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseFee;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentFlowTest extends TestCase
{
    use RefreshDatabase;

    private function learner(): User
    {
        return User::factory()->learner()->create();
    }

    private function adminCourse(): array
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->published()->create(['created_by' => $admin->id]);
        CourseFee::factory()->application()->create(['course_id' => $course->id, 'amount' => 50000]);
        CourseFee::factory()->tuition()->create(['course_id' => $course->id, 'amount' => 800000]);
        CourseFee::factory()->certificate()->create(['course_id' => $course->id, 'amount' => 50000]);

        return [$admin, $course];
    }

    public function test_learner_can_apply_and_pay_all_fees_to_certification(): void
    {
        [, $course] = $this->adminCourse();
        $user = $this->learner();

        $apply = $this->actingAsUser($user)->postJson('/api/v1/enrollments', [
            'course_id' => $course->id,
        ]);
        $apply->assertCreated()->assertJsonPath('data.status', 'applied');
        $enrollmentId = $apply->json('data.id');

        // application fee -> bypass gateway (local)
        $this->actingAsUser($user)->postJson("/api/v1/enrollments/{$enrollmentId}/pay/application")
            ->assertOk();
        $this->assertDatabaseHas('enrollments', ['id' => $enrollmentId, 'status' => 'application_fee_paid']);
        $this->assertDatabaseHas('payments', ['enrollment_id' => $enrollmentId, 'fee_type' => 'application', 'status' => 'paid']);

        // admit (admin)
        $admin = User::factory()->admin()->create();
        $this->actingAsUser($admin)->postJson("/api/v1/admin/enrollments/{$enrollmentId}/admit")
            ->assertOk()
            ->assertJsonPath('data.status', 'admitted');

        // tuition
        $this->actingAsUser($user)->postJson("/api/v1/enrollments/{$enrollmentId}/pay/tuition")
            ->assertOk();
        $this->assertDatabaseHas('enrollments', ['id' => $enrollmentId, 'status' => 'tuition_paid']);

        // complete
        $this->actingAsUser($user)->postJson("/api/v1/enrollments/{$enrollmentId}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        // certificate fee + issue
        $this->actingAsUser($user)->postJson("/api/v1/enrollments/{$enrollmentId}/pay/certificate")
            ->assertOk();
        $this->assertDatabaseHas('enrollments', ['id' => $enrollmentId, 'status' => 'certification']);

        $cert = $this->actingAsUser($user)->postJson("/api/v1/enrollments/{$enrollmentId}/certificate");
        $cert->assertCreated()
            ->assertJsonPath('data.user_name', $user->name)
            ->assertJsonStructure(['data' => ['certificate_reference', 'issued_at']]);

        $this->assertDatabaseHas('enrollments', ['id' => $enrollmentId, 'status' => 'certified']);
        $this->assertDatabaseHas('certificates', ['enrollment_id' => $enrollmentId]);
    }

    public function test_cannot_apply_twice_to_same_course(): void
    {
        [, $course] = $this->adminCourse();
        $user = $this->learner();

        $this->actingAsUser($user)->postJson('/api/v1/enrollments', ['course_id' => $course->id])->assertCreated();
        $this->actingAsUser($user)->postJson('/api/v1/enrollments', ['course_id' => $course->id])
            ->assertStatus(422)
            ->assertJson(['message' => 'You have already applied for this course.']);
    }

    public function test_learner_cannot_pay_for_another_users_enrollment(): void
    {
        [, $course] = $this->adminCourse();
        $first = $this->learner();
        $second = $this->learner();

        $enrollment = $this->actingAsUser($first)->postJson('/api/v1/enrollments', ['course_id' => $course->id])
            ->json('data');

        $this->actingAsUser($second)
            ->postJson("/api/v1/enrollments/{$enrollment['id']}/pay/application")
            ->assertStatus(403);
    }

    public function test_admin_can_reject_an_application(): void
    {
        [, $course] = $this->adminCourse();
        $user = $this->learner();
        $admin = User::factory()->admin()->create();

        $enrollment = $this->actingAsUser($user)->postJson('/api/v1/enrollments', ['course_id' => $course->id])
            ->json('data');

        $this->actingAsUser($admin)
            ->postJson("/api/v1/admin/enrollments/{$enrollment['id']}/reject", ['note' => 'incomplete'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
    }

    public function test_learner_sees_only_own_enrollments(): void
    {
        [, $course] = $this->adminCourse();
        $mine = $this->learner();
        $other = $this->learner();

        $myEnrollment = $this->actingAsUser($mine)->postJson('/api/v1/enrollments', ['course_id' => $course->id])->json('data');
        $this->actingAsUser($other)->postJson('/api/v1/enrollments', ['course_id' => $course->id])->assertCreated();

        $response = $this->actingAsUser($mine)->getJson('/api/v1/enrollments/mine')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($myEnrollment['id'], $ids);
        $this->assertNotContains((int) $myEnrollment['id'] + 1, $ids);
    }
}