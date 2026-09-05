<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_stats_returns_user_course_enrollment_counts(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->learner()->create();
        User::factory()->learner()->create();
        User::factory()->instructor()->create();
        Course::factory()->published()->create(['created_by' => $admin->id]);

        $response = $this->actingAsUser($admin)->getJson('/api/v1/admin/stats')->assertOk();

        $stats = $response->json('data');
        $this->assertEquals(4, $stats['total_users']);
        $this->assertEquals(2, $stats['learners']);
        $this->assertEquals(1, $stats['instructors']);
        $this->assertEquals(1, $stats['admins']);
        $this->assertEquals(1, $stats['total_courses']);
        $this->assertEquals(1, $stats['published_courses']);
        $this->assertArrayHasKey('total_enrollments', $stats);
        $this->assertArrayHasKey('pending_applications', $stats);
    }

    public function test_non_admin_cannot_view_stats(): void
    {
        $learner = User::factory()->learner()->create();

        $this->actingAsUser($learner)->getJson('/api/v1/admin/stats')->assertStatus(403);
    }

    public function test_admin_can_change_user_role_both_ways(): void
    {
        $admin = User::factory()->admin()->create();
        $learner = User::factory()->learner()->create();

        // Elevate learner -> instructor
        $this->actingAsUser($admin)
            ->putJson("/api/v1/admin/users/{$learner->id}", ['role' => 'instructor'])
            ->assertOk()
            ->assertJsonPath('data.role', 'instructor');

        // Demote instructor -> learner
        $this->actingAsUser($admin)
            ->putJson("/api/v1/admin/users/{$learner->id}", ['role' => 'learner'])
            ->assertOk()
            ->assertJsonPath('data.role', 'learner');
    }

    public function test_admin_can_change_user_status(): void
    {
        $admin = User::factory()->admin()->create();
        $learner = User::factory()->learner()->create();

        $this->actingAsUser($admin)
            ->putJson("/api/v1/admin/users/{$learner->id}", ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->actingAsUser($admin)
            ->putJson("/api/v1/admin/users/{$learner->id}", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_admin_cannot_change_own_role(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAsUser($admin)
            ->putJson("/api/v1/admin/users/{$admin->id}", ['role' => 'learner'])
            ->assertStatus(422);
    }

    public function test_non_admin_cannot_manage_users(): void
    {
        $learner = User::factory()->learner()->create();
        $other = User::factory()->learner()->create();

        $this->actingAsUser($learner)
            ->putJson("/api/v1/admin/users/{$other->id}", ['role' => 'instructor'])
            ->assertStatus(403);
    }

    public function test_suspended_user_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'email' => 'suspended@custospark.com',
            'password' => 'password123',
            'status' => User::STATUS_SUSPENDED,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'suspended@custospark.com',
            'password' => 'password123',
        ])->assertStatus(422);
    }
}