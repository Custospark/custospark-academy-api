<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private function courseWithLearners(User $instructor, int $admitted = 2, int $applied = 1): array
    {
        $course = Course::factory()->published()->create(['created_by' => $instructor->id]);
        $learners = [];
        for ($i = 0; $i < $admitted; $i++) {
            $u = User::factory()->learner()->create();
            Enrollment::factory()->create([
                'course_id' => $course->id,
                'user_id' => $u->id,
                'status' => Enrollment::STATUS_ADMITTED,
            ]);
            $learners[] = $u;
        }
        $outsider = User::factory()->learner()->create();
        Enrollment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $outsider->id,
            'status' => Enrollment::STATUS_APPLIED,
        ]);

        return [$course, $learners, $outsider];
    }

    public function test_register_covers_only_admitted_learners(): void
    {
        $instructor = User::factory()->instructor()->create();
        [$course, $learners, $outsider] = $this->courseWithLearners($instructor);

        $roster = $this->actingAsUser($instructor)
            ->getJson("/api/v1/admin/courses/{$course->id}/attendance?date=2026-09-07")
            ->assertOk()->json('data');

        $this->assertCount(2, $roster);
        $this->assertNull($roster[0]['status']);
        $this->assertSame(0, $roster[0]['rate']);
    }

    public function test_mark_single_and_mark_all_with_rate_math(): void
    {
        $instructor = User::factory()->instructor()->create();
        [$course, $learners, $outsider] = $this->courseWithLearners($instructor);

        // Day 1: mark-all present (applied learner silently excluded).
        $all = $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->id}/attendance/mark-all", [
                'date' => '2026-09-07',
                'status' => 'present',
            ])
            ->assertCreated()->json('data');
        $this->assertSame(2, $all['marked']);

        // Day 2: one present, one absent; applied learner reported, not marked.
        $result = $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->id}/attendance", [
                'date' => '2026-09-08',
                'records' => [
                    ['user_id' => $learners[0]->id, 'status' => 'present'],
                    ['user_id' => $learners[1]->id, 'status' => 'absent'],
                    ['user_id' => $outsider->id, 'status' => 'present'],
                ],
            ])
            ->assertCreated()->json('data');
        $this->assertSame(2, $result['marked']);
        $this->assertCount(1, $result['errors']);

        // Rate: learner 1 -> 2/2 = 100; learner 2 -> 1/2 = 50.
        $mine1 = $this->actingAsUser($learners[0])
            ->getJson("/api/v1/courses/{$course->id}/attendance/mine")
            ->assertOk()->json('data');
        $this->assertSame(100, $mine1['rate']['rate']);
        $this->assertSame(2, $mine1['rate']['total_days']);
        $this->assertCount(2, $mine1['records']);

        $mine2 = $this->actingAsUser($learners[1])
            ->getJson("/api/v1/courses/{$course->id}/attendance/mine")
            ->assertOk()->json('data');
        $this->assertSame(50, $mine2['rate']['rate']);
        $this->assertSame(1, $mine2['rate']['absent']);

        // Applied (not admitted) learner has no register.
        $this->actingAsUser($outsider)
            ->getJson("/api/v1/courses/{$course->id}/attendance/mine")
            ->assertForbidden();
    }

    public function test_other_instructor_cannot_touch_the_register(): void
    {
        $instructor = User::factory()->instructor()->create();
        $other = User::factory()->instructor()->create();
        [$course] = $this->courseWithLearners($instructor);

        $this->actingAsUser($other)
            ->getJson("/api/v1/admin/courses/{$course->id}/attendance?date=2026-09-07")
            ->assertForbidden();
        $this->actingAsUser($other)
            ->postJson("/api/v1/admin/courses/{$course->id}/attendance/mark-all", [
                'date' => '2026-09-07',
                'status' => 'present',
            ])
            ->assertForbidden();
    }
}
