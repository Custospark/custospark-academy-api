<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
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
}