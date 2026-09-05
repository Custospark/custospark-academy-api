<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class PlatformController extends Controller
{
    public function stats(): JsonResponse
    {
        if (! request()->user()?->isAdmin()) {
            abort(403, 'Only admins can view platform stats.');
        }

        $countByRole = fn (string $role): int => User::query()->where('role', $role)->count();

        return response()->json([
            'data' => [
                'total_users' => User::query()->count(),
                'learners' => $countByRole(User::ROLE_LEARNER),
                'instructors' => $countByRole(User::ROLE_INSTRUCTOR),
                'admins' => $countByRole(User::ROLE_ADMIN),
                'total_courses' => Course::query()->count(),
                'published_courses' => Course::query()->where('status', Course::STATUS_PUBLISHED)->count(),
                'total_enrollments' => Enrollment::query()->count(),
                'pending_applications' => Enrollment::query()
                    ->where('status', Enrollment::STATUS_APPLIED)
                    ->count(),
            ],
        ]);
    }
}