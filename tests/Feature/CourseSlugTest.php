<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseSlugTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_titles_get_deduplicated_slugs(): void
    {
        $admin = User::factory()->admin()->create();

        $first = $this->actingAsUser($admin)
            ->postJson('/api/v1/admin/courses', ['title' => 'Data Science'])
            ->assertCreated()
            ->json('data');
        $second = $this->actingAsUser($admin)
            ->postJson('/api/v1/admin/courses', ['title' => 'Data Science'])
            ->assertCreated()
            ->json('data');

        $this->assertSame('data-science', $first['slug']);
        $this->assertSame('data-science-2', $second['slug']);
    }

    public function test_public_course_resolves_by_slug_and_by_id(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->published()->create([
            'created_by' => $admin->id,
            'title' => 'Slug Resolution',
        ]);

        $bySlug = $this->getJson("/api/v1/courses/{$course->slug}")->assertOk()->json('data');
        $byId = $this->getJson("/api/v1/courses/{$course->id}")->assertOk()->json('data');

        $this->assertSame($course->id, $bySlug['id']);
        $this->assertSame($course->id, $byId['id']);
        $this->assertSame($course->slug, $bySlug['slug']);
    }

    public function test_unknown_slug_404s(): void
    {
        $this->getJson('/api/v1/courses/no-such-course-anywhere')->assertNotFound();
    }

    public function test_admin_content_endpoints_accept_slugs(): void
    {
        $instructor = User::factory()->instructor()->create();
        $course = Course::factory()->published()->create(['created_by' => $instructor->id]);

        $full = $this->actingAsUser($instructor)
            ->getJson("/api/v1/admin/courses/{$course->slug}/content")
            ->assertOk()
            ->json('data');
        $this->assertSame($course->id, $full['id']);

        $section = $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->slug}/sections", ['title' => 'Module 1'])
            ->assertCreated()
            ->json('data');
        $this->assertSame($course->id, $section['course_id']);
    }

    public function test_admin_course_update_accepts_slug(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->published()->create(['created_by' => $admin->id]);

        $updated = $this->actingAsUser($admin)
            ->putJson("/api/v1/admin/courses/{$course->slug}", ['title' => 'Renamed'])
            ->assertOk()
            ->json('data');

        $this->assertSame('Renamed', $updated['title']);
        // Slug stays stable when the title changes (URLs never break).
        $this->assertSame($course->slug, $updated['slug']);
    }
}
