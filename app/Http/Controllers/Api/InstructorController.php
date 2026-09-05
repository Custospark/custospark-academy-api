<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class InstructorController extends Controller
{
    public function __construct(
        protected UserRepositoryInterface $users,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin();

        $instructors = $this->users->findByRole(User::ROLE_INSTRUCTOR);

        return response()->json([
            'data' => $instructors->map(fn (User $user) => $this->serialize($user))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireAdmin();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($this->users->findByEmail(strtolower($validated['email'])) !== null) {
            throw ValidationException::withMessages([
                'email' => 'This email address is already registered.',
            ]);
        }

        $instructor = $this->users->create([
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
            'role' => User::ROLE_INSTRUCTOR,
            'status' => User::STATUS_ACTIVE,
        ]);

        return response()->json(['data' => $this->serialize($instructor)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin();

        $instructor = $this->users->find($id);
        if ($instructor === null || ! $instructor->isInstructor()) {
            abort(404, 'Instructor not found.');
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'status' => ['sometimes', 'string', 'in:active,suspended'],
            'password' => ['sometimes', 'string', 'min:8'],
        ]);

        $updates = $validated;
        if (isset($updates['password'])) {
            $updates['password'] = Hash::make($updates['password']);
        }

        return response()->json([
            'data' => $this->serialize($this->users->update($instructor, $updates)),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin();

        $instructor = $this->users->find($id);
        if ($instructor === null || ! $instructor->isInstructor()) {
            abort(404, 'Instructor not found.');
        }

        $this->users->delete($instructor);

        return response()->json(['data' => null, 'message' => 'Instructor removed.']);
    }

    private function requireAdmin(): void
    {
        if (! request()->user()?->isAdmin()) {
            abort(403, 'Only admins can manage instructors.');
        }
    }

    private function serialize(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'status' => $user->status,
        ];
    }
}