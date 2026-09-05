<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(
        protected UserRepositoryInterface $users,
    ) {}

    public function register(array $data): User
    {
        if ($this->users->findByEmail(strtolower($data['email'])) !== null) {
            throw ValidationException::withMessages([
                'email' => 'This email address is already registered.',
            ]);
        }

        return $this->users->create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'password' => Hash::make($data['password']),
            'phone' => $data['phone'] ?? null,
            'role' => $data['role'] ?? User::ROLE_LEARNER,
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function attempt(string $email, string $password): User
    {
        $user = $this->users->findByEmail(strtolower($email));
        if ($user === null || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'email' => 'This account is suspended.',
            ]);
        }

        return $user;
    }

    public function issueToken(User $user, string $device = 'web'): string
    {
        return $user->createToken($device)->plainTextToken;
    }

    public function revokeTokens(User $user): void
    {
        $user->tokens()->delete();
    }

    /**
     * Send a password reset link. Always returns success to avoid leaking
     * whether an email is registered (mirrors Laravel best practice).
     */
    public function sendPasswordResetLink(string $email): string
    {
        return Password::broker()->sendResetLink(
            ['email' => strtolower($email)],
            fn (User $user, string $token) => $user->notify(
                new ResetPasswordNotification($token, $user->email),
            ),
        );
    }

    public function resetPassword(array $data): string
    {
        $response = Password::broker()->reset(
            $data,
            function (User $user, string $password): void {
                $this->users->update($user, [
                    'password' => Hash::make($password),
                ]);
                $user->tokens()->delete();
            },
        );

        if ($response !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => $this->translateResetStatus($response),
            ]);
        }

        return $response;
    }

    protected function translateResetStatus(string $status): string
    {
        return match ($status) {
            Password::INVALID_USER => 'We cannot find a user with that email address.',
            Password::INVALID_TOKEN => 'This password reset token is invalid or has expired.',
            Password::INVALID_PASSWORD => 'The new password is invalid.',
            default => 'We were unable to reset your password. Please try again.',
        };
    }
}