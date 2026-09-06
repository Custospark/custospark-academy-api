<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Schedule;
use App\Models\User;
use App\Services\CourseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Course schedules (live sessions, deadlines, office hours).
 *
 * - Staff (admins any course, instructors their own) create/update/delete.
 * - Learners see schedules for courses they are enrolled in via /schedules/mine,
 *   and any visitor can see the schedule of a published course.
 */
class ScheduleController extends Controller
{
    public function __construct(
        protected CourseService $courses,
    ) {}

    /** Public: schedule for a course (published, or the caller is enrolled/staff). */
    public function index(Request $request, string|int $courseId): JsonResponse
    {
        $courseId = $this->courseKey($courseId);
        $course = $this->courses->findCourse($courseId);
        if ($course === null) {
            abort(404, 'Course not found.');
        }

        $user = $request->user() ?? auth('sanctum')->user();
        if (! $course->isPublished() && ! $this->canSeeUnpublished($course, $user)) {
            abort(404, 'Course not found.');
        }

        return response()->json(['data' => array_map(
            fn (Schedule $s) => $this->serialize($s),
            $this->courses->schedulesForCourse($courseId),
        )]);
    }

    /** Learner: every upcoming/past session across their enrolled courses. */
    public function mine(Request $request): JsonResponse
    {
        return response()->json(['data' => array_map(
            fn (Schedule $s) => $this->serialize($s),
            $this->courses->schedulesForLearner((int) $request->user()->id),
        )]);
    }

    public function store(Request $request, string|int $courseId): JsonResponse
    {
        $courseId = $this->courseKey($courseId);
        $course = $this->courses->findCourse($courseId);
        if ($course === null) {
            abort(404, 'Course not found.');
        }
        $this->authorizeManage($course, $request->user());

        $validated = $this->validatePayload($request);

        $schedule = $this->courses->createSchedule($course->id, [
            ...$validated,
            'instructor_id' => $validated['instructor_id'] ?? ($request->user()->isInstructor() ? $request->user()->id : null),
        ]);

        return response()->json(['data' => $this->serialize($schedule->load(['course', 'instructor']))], 201);
    }

    public function update(Request $request, string|int $courseId, int $scheduleId): JsonResponse
    {
        $courseId = $this->courseKey($courseId);
        $schedule = $this->requireSchedule($courseId, $scheduleId);
        $this->authorizeManage($schedule->course, $request->user());

        $validated = $this->validatePayload($request, partial: true);

        $schedule = $this->courses->updateSchedule($schedule, $validated);

        return response()->json(['data' => $this->serialize($schedule->load(['course', 'instructor']))]);
    }

    public function destroy(Request $request, string|int $courseId, int $scheduleId): JsonResponse
    {
        $courseId = $this->courseKey($courseId);
        $schedule = $this->requireSchedule($courseId, $scheduleId);
        $this->authorizeManage($schedule->course, $request->user());

        $this->courses->deleteSchedule($schedule);

        return response()->json(['data' => null, 'message' => 'Schedule removed.']);
    }

    private function validatePayload(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'instructor_id' => ['nullable', 'integer', 'exists:users,id'],
            'starts_at' => [$req, 'date'],
            'ends_at' => [$req, 'date', 'after:starts_at'],
            'location' => ['nullable', 'string', 'max:255'],
            'is_online' => ['nullable', 'boolean'],
        ]);
    }

    /** Resolve a slug-or-id course key to its numeric id for scoping. */
    private function courseKey(string|int $courseId): int
    {
        return Course::resolveByKeyOrFail($courseId)->id;
    }

    private function requireSchedule(string|int $courseId, int $scheduleId): Schedule
    {
        $schedule = $this->courses->findSchedule($scheduleId);
        if ($schedule === null || (int) $schedule->course_id !== $courseId) {
            abort(404, 'Schedule not found.');
        }

        return $schedule;
    }

    /** Admins manage any course's schedule; instructors only their own courses. */
    private function authorizeManage(?Course $course, ?User $user): void
    {
        if ($user === null) {
            abort(401);
        }
        if ($course === null) {
            abort(404, 'Course not found.');
        }
        if ($user->isAdmin()) {
            return;
        }
        if ($user->isInstructor() && (int) $course->created_by === (int) $user->id) {
            return;
        }

        abort(403, 'You can only manage schedules for courses you created.');
    }

    private function canSeeUnpublished(Course $course, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }
        if ($user->isAdmin() || (int) $course->created_by === (int) $user->id) {
            return true;
        }

        return $course->enrollments()->where('user_id', $user->id)->exists();
    }

    private function serialize(Schedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'course_id' => $schedule->course_id,
            'course_title' => $schedule->course?->title,
            'instructor_id' => $schedule->instructor_id,
            'instructor_name' => $schedule->instructor?->name,
            'title' => $schedule->title,
            'starts_at' => $schedule->starts_at?->toIso8601String(),
            'ends_at' => $schedule->ends_at?->toIso8601String(),
            'location' => $schedule->location,
            'is_online' => (bool) $schedule->is_online,
        ];
    }
}
