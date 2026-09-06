<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_own_profile_but_not_email(): void
    {
        $user = User::factory()->learner()->create();

        $data = $this->actingAsUser($user)
            ->putJson('/api/v1/account/profile', [
                'name' => 'New Name',
                'phone' => '+256700000001',
                'email' => 'hacker@example.com',
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame('New Name', $data['name']);
        $this->assertSame('+256700000001', $data['phone']);
        $this->assertSame($user->email, $data['email']);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'New Name',
            'email' => $user->email,
        ]);
    }

    public function test_profile_update_requires_a_name(): void
    {
        $user = User::factory()->learner()->create();

        $this->actingAsUser($user)
            ->putJson('/api/v1/account/profile', ['name' => ''])
            ->assertStatus(422);
    }

    public function test_user_can_change_password_with_current_password(): void
    {
        $user = User::factory()->learner()->create();

        $this->actingAsUser($user)
            ->putJson('/api/v1/account/password', [
                'current_password' => 'password',
                'password' => 'new-secret-1',
                'password_confirmation' => 'new-secret-1',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('new-secret-1', $user->fresh()->password));
        $this->assertFalse(Hash::check('password', $user->fresh()->password));
    }

    public function test_password_change_rejects_wrong_current_password(): void
    {
        $user = User::factory()->learner()->create();

        $this->actingAsUser($user)
            ->putJson('/api/v1/account/password', [
                'current_password' => 'wrong',
                'password' => 'new-secret-1',
                'password_confirmation' => 'new-secret-1',
            ])
            ->assertStatus(422);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_guests_cannot_touch_account_endpoints(): void
    {
        $this->putJson('/api/v1/account/profile', ['name' => 'X'])->assertStatus(401);
        $this->putJson('/api/v1/account/password', [
            'current_password' => 'x',
            'password' => 'y1234567',
            'password_confirmation' => 'y1234567',
        ])->assertStatus(401);
    }

    public function test_user_can_upload_and_replace_avatar(): void
    {
        Storage::fake('public');
        $user = User::factory()->learner()->create();

        $first = $this->actingAsUser($user)
            ->post('/api/v1/account/avatar', [
                'avatar' => UploadedFile::fake()->image('me.jpg'),
            ])
            ->assertOk()
            ->json('data');

        $this->assertStringStartsWith('http', $first['avatar_url']);
        $dbPath = $user->fresh()->getRawOriginal('avatar_url');
        $this->assertNotNull($dbPath);
        Storage::disk('public')->assertExists($dbPath);

        $second = $this->actingAsUser($user)
            ->post('/api/v1/account/avatar', [
                'avatar' => UploadedFile::fake()->image('me2.jpg'),
            ])
            ->assertOk()
            ->json('data');

        $this->assertNotSame($first['avatar_url'], $second['avatar_url']);
        Storage::disk('public')->assertMissing($dbPath);
    }

    public function test_avatar_url_accessor_resolves_every_stored_form(): void
    {
        $user = User::factory()->learner()->create();

        $this->assertNull($user->fresh()->avatar_url);

        $user->forceFill(['avatar_url' => 'avatars/me.jpg'])->save();
        $this->assertStringStartsWith('http', $user->fresh()->avatar_url);
        $this->assertStringEndsWith('/storage/avatars/me.jpg', $user->fresh()->avatar_url);

        $user->forceFill(['avatar_url' => '/storage/avatars/me.jpg'])->save();
        $this->assertStringEndsWith('/storage/avatars/me.jpg', $user->fresh()->avatar_url);

        $user->forceFill(['avatar_url' => 'https://cdn.example.com/me.jpg'])->save();
        $this->assertSame('https://cdn.example.com/me.jpg', $user->fresh()->avatar_url);

        // /auth/me exposes the resolved URL too.
        $me = $this->actingAsUser($user)->getJson('/api/v1/auth/me')->assertOk()->json('data');
        $this->assertSame('https://cdn.example.com/me.jpg', $me['avatar_url']);
    }
}
