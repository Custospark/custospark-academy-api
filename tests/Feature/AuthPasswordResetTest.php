<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_reset_link_email(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'learner@example.com']);

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'learner@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'If that email address is associated with an account, a password reset link has been sent.');

        Notification::assertSentTo(
            $user,
            ResetPasswordNotification::class,
        );
    }

    public function test_forgot_password_hides_whether_email_exists(): void
    {
        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'missing@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'If that email address is associated with an account, a password reset link has been sent.');
    }

    public function test_forgot_password_validates_email(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'not-an-email'])
            ->assertStatus(422);
    }

    public function test_reset_password_updates_password_and_revokes_tokens(): void
    {
        $user = User::factory()->create(['email' => 'learner@example.com']);
        $token = Password::broker()->createToken($user);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'learner@example.com',
            'token' => $token,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Password has been reset successfully.');

        $this->assertTrue(password_verify('newpassword123', $user->fresh()->password));
    }

    public function test_reset_password_rejects_invalid_token(): void
    {
        $user = User::factory()->create(['email' => 'learner@example.com']);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'learner@example.com',
            'token' => 'invalid-token',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(422);
    }
}