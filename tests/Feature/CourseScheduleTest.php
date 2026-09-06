<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Live Session 1: Kickoff',
            'starts_at' => now()->addDays(3)->setTime(18, 0)->toIso8601String(),
            'ends_at' => now()->addDays(3)->setTime(20, 0)->toIso8601String(),
            'location' => 'Zoom',
            'is_online' => true,
            ...$overrides,
        ];
    }

    public function test_instructor_manages_schedules_only_on_their_own_courses_and_admin_on_any(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->instructor()->create();
        $other = User::factory()->instructor()->create();
        $course = Course::factory()->published()->create(['created_by' => $owner->id]);

        // Other instructor: forbidden
        $this->actingAsUser($other)->postJson("/api/v1/admin/courses/{$course->id}/schedules", $this->payload())
            ->assertForbidden();

        // Owner: created, defaults instructor_id to self
        $created = $this->actingAsUser($owner)->postJson("/api/v1/admin/courses/{$course->id}/schedules", $this->payload());
        $created->assertCreated()
            ->assertJsonPath('data.title', 'Live Session 1: Kickoff')
            ->assertJsonPath('data.instructor_id', $owner->id)
            ->assertJsonPath('data.is_online', true);
        $scheduleId = (int) $created->json('data.id');

        // Owner: update
        $this->actingAsUser($owner)->putJson("/api/v1/admin/courses/{$course->id}/schedules/{$scheduleId}", [
            'title' => 'Live Session 1: Kickoff (moved)',
            'location' => 'Google Meet',
        ])->assertOk()
            ->assertJsonPath('data.title', 'Live Session 1: Kickoff (moved)')
            ->assertJsonPath('data.location', 'Google Meet');

        // Other instructor: cannot update or delete
        $this->actingAsUser($other)->putJson("/api/v1/admin/courses/{$course->id}/schedules/{$scheduleId}", ['title' => 'x'])
            ->assertForbidden();
        $this->actingAsUser($other)->deleteJson("/api/v1/admin/courses/{$course->id}/schedules/{$scheduleId}")
            ->assertForbidden();

        // Admin: can add to any course and delete
        $adminCreated = $this->actingAsUser($admin)->postJson("/api/v1/admin/courses/{$course->id}/schedules", $this->payload([
            'title' => 'Admin-added deadline',
        ]));
        $adminCreated->assertCreated();

        $this->actingAsUser($admin)->deleteJson("/api/v1/admin/courses/{$course->id}/schedules/{$scheduleId}")
            ->assertOk();
        $this->assertSoftDeleted('schedules', ['id' => $scheduleId]);

        // Learners can never manage
        $learner = User::factory()->learner()->create();
        $this->actingAsUser($learner)->postJson("/api/v1/admin/courses/{$course->id}/schedules", $this->payload())
            ->assertForbidden();
    }

    public function test_validation_rejects_end_before_start(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->published()->create(['created_by' => $admin->id]);

        $this->actingAsUser($admin)->postJson("/api/v1/admin/courses/{$course->id}/schedules", $this->payload([
            'starts_at' => now()->addDays(3)->toIso8601String(),
            'ends_at' => now()->addDays(2)->toIso8601String(),
        ]))->assertStatus(422)->assertJsonValidationErrors(['ends_at']);
    }

    public function test_learner_sees_schedules_only_for_enrolled_courses(): void
    {
        $admin = User::factory()->admin()->create();
        $learner = User::factory()->learner()->create();

        $enrolledCourse = Course::factory()->published()->create(['created_by' => $admin->id, 'title' => 'Enrolled Course']);
        $otherCourse = Course::factory()->published()->create(['created_by' => $admin->id, 'title' => 'Other Course']);
        $cancelledCourse = Course::factory()->published()->create(['created_by' => $admin->id, 'title' => 'Cancelled Course']);

        Enrollment::query()->create(['course_id' => $enrolledCourse->id, 'user_id' => $learner->id, 'status' => Enrollment::STATUS_IN_PROGRESS, 'applied_at' => now()]);
        Enrollment::query()->create(['course_id' => $cancelledCourse->id, 'user_id' => $learner->id, 'status' => Enrollment::STATUS_CANCELLED, 'applied_at' => now()]);

        foreach ([$enrolledCourse, $otherCourse, $cancelledCourse] as $course) {
            $this->actingAsUser($admin)->postJson("/api/v1/admin/courses/{$course->id}/schedules", $this->payload([
                'title' => 'Session for '.$course->title,
            ]))->assertCreated();
        }

        $mine = $this->actingAsUser($learner)->getJson('/api/v1/schedules/mine');
        $mine->assertOk();
        $titles = collect($mine->json('data'))->pluck('title')->all();
        $this->assertSame(['Session for Enrolled Course'], $titles);
        $this->assertSame('Enrolled Course', $mine->json('data.0.course_title'));

        // Public per-course schedule for a published course works for guests
        $this->asGuest()->getJson("/api/v1/courses/{$otherCourse->id}/schedules")->assertOk()->assertJsonCount(1, 'data');

        // Unpublishing hides the schedule from strangers but not from the enrolled learner
        $enrolledCourse->update(['status' => Course::STATUS_DRAFT]);
        $this->asGuest()->getJson("/api/v1/courses/{$enrolledCourse->id}/schedules")->assertNotFound();
        $this->actingAsUser($learner)->getJson("/api/v1/courses/{$enrolledCourse->id}/schedules")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAsUser($learner)->getJson('/api/v1/schedules/mine')->assertOk()->assertJsonCount(1, 'data');
    }
}
