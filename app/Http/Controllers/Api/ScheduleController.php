<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CourseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    public function __construct(
        protected CourseService $courses,
    ) {}

    public function index(int $courseId): JsonResponse
    {
        return response()->json(['data' => $this->courses->schedulesForCourse($courseId)]);
    }

    public function store(Request $request, int $courseId): JsonResponse
    {
        $this->requireAdmin();

        $course = $this->courses->findCourse($courseId);
        if ($course === null) {
            abort(404, 'Course not found.');
        }

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'instructor_id' => ['nullable', 'integer', 'exists:users,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'location' => ['nullable', 'string', 'max:255'],
            'is_online' => ['nullable', 'boolean'],
        ]);

        return response()->json([
            'data' => $this->courses->createSchedule($courseId, $validated),
        ], 201);
    }

    private function requireAdmin(): void
    {
        if (! request()->user()?->isAdmin()) {
            abort(403, 'Only admins can manage schedules.');
        }
    }
}