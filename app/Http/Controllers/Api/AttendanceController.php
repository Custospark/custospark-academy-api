<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function __construct(
        protected AttendanceService $attendance,
    ) {}

    /** Class register for a date: every enrolled learner + their mark + rate. */
    public function roster(Request $request, string|int $courseId): JsonResponse
    {
        $course = Course::resolveByKeyOrFail($courseId);
        $this->authorizeCourseAudience($course, $request->user());

        $validated = $request->validate(['date' => ['nullable', 'date']]);

        return response()->json([
            'data' => $this->attendance->roster($course, $validated['date'] ?? now()->toDateString()),
        ]);
    }

    /** Mark specific learners for a date. */
    public function mark(Request $request, string|int $courseId): JsonResponse
    {
        $course = Course::resolveByKeyOrFail($courseId);
        $this->authorizeCourseAudience($course, $request->user());

        $validated = $request->validate([
            'date' => ['required', 'date'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.user_id' => ['required', 'integer'],
            'records.*.status' => ['required', 'string'],
        ]);

        $result = $this->attendance->mark($course, $validated['date'], $validated['records'], (int) $request->user()->id);

        return response()->json(['data' => $result], 201);
    }

    /** Mark the whole class with one status for a date. */
    public function markAll(Request $request, string|int $courseId): JsonResponse
    {
        $course = Course::resolveByKeyOrFail($courseId);
        $this->authorizeCourseAudience($course, $request->user());

        $validated = $request->validate([
            'date' => ['required', 'date'],
            'status' => ['required', 'string'],
        ]);

        $result = $this->attendance->markAll($course, $validated['date'], $validated['status'], (int) $request->user()->id);

        return response()->json(['data' => $result], 201);
    }

    /** Learner's own attendance records + auto-calculated rate (admitted learners). */
    public function mine(Request $request, string|int $courseId): JsonResponse
    {
        $course = Course::resolveByKeyOrFail($courseId);
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }
        if (! $user->isAdmin() && ! $user->isInstructor()) {
            $admitted = \App\Models\Enrollment::query()
                ->where('course_id', $course->id)
                ->where('user_id', $user->id)
                ->whereIn('status', \App\Services\AttendanceService::ADMITTED_STATUSES)
                ->exists();
            if (! $admitted) {
                abort(403, 'Attendance records are for admitted learners.');
            }
        }

        return response()->json([
            'data' => $this->attendance->mine((int) $user->id, (int) $course->id),
        ]);
    }

    /** Admins reach any register; instructors only their own courses. */
    private function authorizeCourseAudience(Course $course, $user): void
    {
        if ($user === null) {
            abort(401);
        }
        if ($user->isAdmin()) {
            return;
        }
        if ($user->isInstructor() && (int) $course->created_by === (int) $user->id) {
            return;
        }
        abort(403, 'You can only manage attendance for courses you created.');
    }
}
