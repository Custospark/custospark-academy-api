<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserAdminController extends Controller
{
    public function __construct(
        protected UserRepositoryInterface $users,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin();

        $users = User::query()
            ->withCount('createdCourses as course_count')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $users->map(fn (User $user) => $this->serialize($user))->values(),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin();

        $user = $this->users->find($id);
        if ($user === null) {
            abort(404, 'User not found.');
        }

        // Prevent the admin from changing their own role/status.
        if ((int) $user->id === (int) $request->user()->id) {
            abort(422, 'You cannot change your own role or status.');
        }

        $validated = $request->validate([
            'role' => ['sometimes', 'string', 'in:learner,instructor,admin'],
            'status' => ['sometimes', 'string', 'in:active,suspended,pending'],
        ]);

        $updates = [];
        if (isset($validated['role'])) {
            $updates['role'] = $validated['role'];
        }
        if (isset($validated['status'])) {
            $updates['status'] = $validated['status'];
        }

        if ($updates === []) {
            abort(422, 'Provide a role or status to update.');
        }

        return response()->json([
            'data' => $this->serialize($this->users->update($user, $updates)),
        ]);
    }

    private function requireAdmin(): void
    {
        if (! request()->user()?->isAdmin()) {
            abort(403, 'Only admins can manage users.');
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
            'course_count' => $user->course_count ?? 0,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}