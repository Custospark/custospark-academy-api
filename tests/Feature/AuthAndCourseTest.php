<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthAndCourseTest extends TestCase
{
    use RefreshDatabase;

    public function test_learner_can_register_and_login(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'name' => 'Jane Learner',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'phone' => '+256700000000',
        ]);

        $register->assertCreated()
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'email', 'role']]])
            ->assertJsonPath('data.user.role', 'learner');

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'password123',
        ]);
        $login->assertOk()->assertJsonStructure(['data' => ['token']]);
    }

    public function test_duplicate_registration_is_rejected(): void
    {
        $payload = [
            'name' => 'Jane',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'phone' => '+256700000000',
        ];

        $this->postJson('/api/v1/auth/register', $payload)->assertCreated();

        $this->postJson('/api/v1/auth/register', $payload)->assertStatus(422);
    }

    public function test_registration_requires_phone(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Jane',
            'email' => 'jane@example.com',
            'password' => 'password123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::factory()->learner()->create();

        $this->actingAsUser($user)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_only_published_courses_are_visible_to_learners(): void
    {
        $admin = User::factory()->admin()->create();
        Course::factory()->published()->create(['created_by' => $admin->id]);
        Course::factory()->draft()->create(['created_by' => $admin->id]);

        $response = $this->getJson('/api/v1/courses')->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_only_admin_can_create_course(): void
    {
        $learner = User::factory()->learner()->create();

        $this->actingAsUser($learner)
            ->postJson('/api/v1/admin/courses', [
                'title' => 'Hack',
                'slug' => 'hack',
            ])
            ->assertStatus(403);

        $admin = User::factory()->admin()->create();

        $this->actingAsUser($admin)
            ->postJson('/api/v1/admin/courses', [
                'title' => 'Python',
                'slug' => 'python',
                'status' => 'published',
                'tuition_fee' => 500000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Python')
            ->assertJsonCount(1, 'data.fees');
    }

    public function test_guest_cannot_access_protected_routes(): void
    {
        $this->getJson('/api/v1/enrollments/mine')->assertStatus(401);
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }
}