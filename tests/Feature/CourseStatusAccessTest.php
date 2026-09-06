<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseFee;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\User;
use App\Services\CertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseStatusAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_courses_never_appear_in_catalog(): void
    {
        $admin = User::factory()->admin()->create();
        $learner = User::factory()->learner()->create();

        $published = Course::factory()->published()->create([
            'title' => 'Published Masterclass',
            'created_by' => $admin->id,
        ]);
        $draft = Course::factory()->draft()->create([
            'title' => 'Secret Draft Course',
            'created_by' => $admin->id,
        ]);
        $archived = Course::factory()->create([
            'title' => 'Old Archived Course',
            'status' => Course::STATUS_ARCHIVED,
            'created_by' => $admin->id,
        ]);

        // Guest catalog
        $guestRes = $this->asGuest()->getJson('/api/v1/courses');
        $guestRes->assertOk();
        $titles = collect($guestRes->json('data'))->pluck('title')->all();
        $this->assertContains('Published Masterclass', $titles);
        $this->assertNotContains('Secret Draft Course', $titles);
        $this->assertNotContains('Old Archived Course', $titles);

        // Authenticated learner catalog
        $learnerRes = $this->actingAsUser($learner)->getJson('/api/v1/courses');
        $learnerRes->assertOk();
        $learnerTitles = collect($learnerRes->json('data'))->pluck('title')->all();
        $this->assertContains('Published Masterclass', $learnerTitles);
        $this->assertNotContains('Secret Draft Course', $learnerTitles);
        $this->assertNotContains('Old Archived Course', $learnerTitles);

        // Authenticated admin catalog view should also only see published courses in catalog
        $adminRes = $this->actingAsUser($admin)->getJson('/api/v1/courses');
        $adminRes->assertOk();
        $adminTitles = collect($adminRes->json('data'))->pluck('title')->all();
        $this->assertContains('Published Masterclass', $adminTitles);
        $this->assertNotContains('Secret Draft Course', $adminTitles);
        $this->assertNotContains('Old Archived Course', $adminTitles);
    }

    public function test_changing_course_to_draft_does_not_revoke_enrolled_learner_access(): void
    {
        $admin = User::factory()->admin()->create();
        $learner = User::factory()->learner()->create();
        $stranger = User::factory()->learner()->create();

        $course = Course::factory()->published()->create([
            'title' => 'Fullstack AI Engineering',
            'created_by' => $admin->id,
            'delivery_mode' => Course::DELIVERY_SELF_PACED,
        ]);

        CourseFee::factory()->application()->create(['course_id' => $course->id, 'amount' => 0]);
        CourseFee::factory()->tuition()->create(['course_id' => $course->id, 'amount' => 0]);
        CourseFee::factory()->certificate()->create(['course_id' => $course->id, 'amount' => 50000]);

        // Learner applies while course is published
        $apply = $this->actingAsUser($learner)->postJson('/api/v1/enrollments', [
            'course_id' => $course->id,
        ]);
        $apply->assertCreated();
        $enrollmentId = (int) $apply->json('data.id');

        // Course is now unpublished (set to draft by instructor/admin)
        $course->update(['status' => Course::STATUS_DRAFT]);
        $this->assertEquals(Course::STATUS_DRAFT, $course->fresh()->status);

        // 1. Stranger cannot see the course (404)
        $this->actingAsUser($stranger)->getJson("/api/v1/courses/{$course->id}")
            ->assertNotFound();

        // 2. Enrolled learner CAN still see course details
        $learnerCourse = $this->actingAsUser($learner)->getJson("/api/v1/courses/{$course->id}");
        $learnerCourse->assertOk()
            ->assertJsonPath('data.title', 'Fullstack AI Engineering');

        // 3. Enrolled learner CAN still access course content
        $content = $this->actingAsUser($learner)->getJson("/api/v1/courses/{$course->id}/content");
        $content->assertOk();

        // 4. Enrolled learner CAN complete enrollment
        $complete = $this->actingAsUser($learner)->postJson("/api/v1/enrollments/{$enrollmentId}/complete");
        $complete->assertOk()
            ->assertJsonPath('data.status', 'completed');

        // 5. Enrolled learner CAN pay certificate fee & get certificate issued
        $payCert = $this->actingAsUser($learner)->postJson("/api/v1/enrollments/{$enrollmentId}/pay/certificate");
        $payCert->assertOk();

        $issueCert = $this->actingAsUser($learner)->postJson("/api/v1/enrollments/{$enrollmentId}/certificate");
        $issueCert->assertCreated();
        $certId = (int) $issueCert->json('data.id');

        // 6. Enrolled learner CAN view and download their certificate PDF
        $this->actingAsUser($learner)->get("/api/v1/certificates/{$certId}/pdf")
            ->assertOk();
        $this->actingAsUser($learner)->get("/api/v1/certificates/{$certId}/download")
            ->assertOk();
    }

    public function test_new_students_cannot_apply_to_draft_course(): void
    {
        $admin = User::factory()->admin()->create();
        $learner = User::factory()->learner()->create();

        $draft = Course::factory()->draft()->create([
            'title' => 'Upcoming Unreleased Course',
            'created_by' => $admin->id,
        ]);

        $apply = $this->actingAsUser($learner)->postJson('/api/v1/enrollments', [
            'course_id' => $draft->id,
        ]);

        $apply->assertStatus(422)
            ->assertJsonPath('message', 'You cannot apply for an unpublished course.');
    }
}
