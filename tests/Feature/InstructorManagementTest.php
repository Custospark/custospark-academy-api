<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseFee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstructorManagementTest extends TestCase
{
    use RefreshDatabase;

    private function courseFor(User $creator): Course
    {
        $course = Course::factory()->published()->create(['created_by' => $creator->id]);
        CourseFee::factory()->tuition()->create(['course_id' => $course->id, 'amount' => 500000]);

        return $course;
    }

    public function test_instructor_sees_only_own_courses_in_admin_catalog(): void
    {
        $instructor = User::factory()->instructor()->create();
        $otherInstructor = User::factory()->instructor()->create();
        $mine = $this->courseFor($instructor);
        $theirs = $this->courseFor($otherInstructor);

        $response = $this->actingAsUser($instructor)->getJson('/api/v1/admin/courses')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    public function test_instructor_can_update_own_course_but_not_others(): void
    {
        $instructor = User::factory()->instructor()->create();
        $otherInstructor = User::factory()->instructor()->create();
        $mine = $this->courseFor($instructor);
        $theirs = $this->courseFor($otherInstructor);

        $this->actingAsUser($instructor)
            ->putJson("/api/v1/admin/courses/{$mine->id}", ['title' => 'My updated course'])
            ->assertOk()
            ->assertJsonPath('data.title', 'My updated course');

        $this->actingAsUser($instructor)
            ->putJson("/api/v1/admin/courses/{$theirs->id}", ['title' => 'Nope'])
            ->assertStatus(403);
    }

    public function test_instructor_cannot_delete_others_course(): void
    {
        $instructor = User::factory()->instructor()->create();
        $otherInstructor = User::factory()->instructor()->create();
        $theirs = $this->courseFor($otherInstructor);

        $this->actingAsUser($instructor)
            ->deleteJson("/api/v1/admin/courses/{$theirs->id}")
            ->assertStatus(403);
    }

    public function test_learner_cannot_manage_courses(): void
    {
        $learner = User::factory()->learner()->create();

        $this->actingAsUser($learner)
            ->postJson('/api/v1/admin/courses', [
                'title' => 'Hack',
                'slug' => 'hack',
            ])
            ->assertStatus(403);
    }

    public function test_admin_can_list_and_create_instructors(): void
    {
        $admin = User::factory()->admin()->create();

        $list = $this->actingAsUser($admin)->getJson('/api/v1/admin/instructors')->assertOk();
        $this->assertIsArray($list->json('data'));

        $this->actingAsUser($admin)->postJson('/api/v1/admin/instructors', [
            'name' => 'New Instructor',
            'email' => 'new@custospark.com',
            'password' => 'password123',
        ])->assertCreated()
            ->assertJsonPath('data.role', 'instructor');
    }

    public function test_admin_can_update_and_delete_instructor(): void
    {
        $admin = User::factory()->admin()->create();
        $instructor = User::factory()->instructor()->create();

        $this->actingAsUser($admin)
            ->putJson("/api/v1/admin/instructors/{$instructor->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');

        $this->actingAsUser($admin)
            ->deleteJson("/api/v1/admin/instructors/{$instructor->id}")
            ->assertOk();

        $this->assertSoftDeleted('users', ['id' => $instructor->id]);
    }

    public function test_admin_can_search_instructors(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->instructor()->create(['name' => 'Alice Wonder']);
        User::factory()->instructor()->create(['name' => 'Bob Builder']);

        $response = $this->actingAsUser($admin)
            ->getJson('/api/v1/admin/instructors?q=alice')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Alice Wonder', $names);
        $this->assertNotContains('Bob Builder', $names);
    }

    public function test_course_catalog_search_filters_by_title_and_category(): void
    {
        $admin = User::factory()->admin()->create();
        $this->courseFor($admin); // created with random factory title/category

        Course::factory()->published()->create([
            'title' => 'Data Science Bootcamp',
            'category' => 'Software & Coding',
            'created_by' => $admin->id,
        ]);
        Course::factory()->published()->create([
            'title' => 'Digital Marketing',
            'category' => 'Business',
            'created_by' => $admin->id,
        ]);

        $response = $this->getJson('/api/v1/courses?q=data')->assertOk();
        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertContains('Data Science Bootcamp', $titles);
        $this->assertNotContains('Digital Marketing', $titles);
    }

    public function test_course_slug_is_auto_generated_and_deduplicated(): void
    {
        $admin = User::factory()->admin()->create();

        $first = $this->actingAsUser($admin)
            ->postJson('/api/v1/admin/courses', ['title' => 'Python Mastery'])
            ->assertCreated()
            ->json('data');
        $this->assertSame('python-mastery', $first['slug']);

        $second = $this->actingAsUser($admin)
            ->postJson('/api/v1/admin/courses', ['title' => 'Python Mastery'])
            ->assertCreated()
            ->json('data');
        $this->assertSame('python-mastery-2', $second['slug']);
    }

    public function test_course_code_and_prerequisites_are_optional(): void
    {
        $admin = User::factory()->admin()->create();

        $course = $this->actingAsUser($admin)
            ->postJson('/api/v1/admin/courses', [
                'title' => 'Advanced Django',
                'course_code' => 'DJ-301',
                'prerequisites' => 'Basic Python',
                'level' => 'advanced',
                'duration_hours' => 40,
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('DJ-301', $course['course_code']);
        $this->assertSame('Basic Python', $course['prerequisites']);
        $this->assertSame('advanced', $course['level']);
    }

    public function test_course_update_accepts_null_optional_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $course = $this->courseFor($admin);

        $this->actingAsUser($admin)
            ->putJson("/api/v1/admin/courses/{$course->id}", [
                'course_code' => null,
                'prerequisites' => null,
                'title' => 'Updated Title',
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated Title');
    }

    public function test_non_admin_cannot_manage_instructors(): void
    {
        $instructor = User::factory()->instructor()->create();

        $this->actingAsUser($instructor)
            ->getJson('/api/v1/admin/instructors')
            ->assertStatus(403);
    }
}