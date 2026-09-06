<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\CourseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    public function __construct(
        protected CourseService $courses,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // Public route: resolve a logged-in user (via bearer token) so the
        // catalog can surface their enrollment per course, while staying
        // fully accessible to guests. Course catalog strictly lists published courses.
        $user = $request->user() ?? auth('sanctum')->user();
        $search = $request->query('q');

        $courses = $this->courses->publishedCourses($search);

        return response()->json(['data' => array_map(
            fn (Course $c) => $this->serialize($c, user: $user),
            $courses,
        )]);
    }

    public function show(int $id): JsonResponse
    {
        $course = $this->courses->findCourse($id);
        if ($course === null) {
            abort(404, 'Course not found.');
        }

        $user = request()->user() ?? auth('sanctum')->user();

        // Unpublished courses (draft/archived) are hidden from the public catalog,
        // but enrolled students, administrators, and the course creator must never lose access.
        if (! $course->isPublished()) {
            $isEnrolled = $user ? $course->enrollments()->where('user_id', $user->id)->exists() : false;
            $isStaff = $user && ($user->isAdmin() || (int) $course->created_by === (int) $user->id);

            if (! $isEnrolled && ! $isStaff) {
                abort(404, 'Course not found.');
            }
        }

        return response()->json(['data' => $this->serialize($course, withSchedule: true, user: $user)]);
    }

    /**
     * Course management listing. Admins see every course (any status);
     * instructors only the courses they created. Each row carries an
     * enrollment summary (enrolled / paid tuition / certified ...).
     */
    public function manageIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        if (! $user->isAdmin() && ! $user->isInstructor()) {
            abort(403, 'Only admins and instructors can manage courses.');
        }

        $search = $request->query('q');

        $courses = $user->isAdmin()
            ? $this->courses->allCourses($search)
            : $this->courses->coursesForCreator((int) $user->id, $search);

        return response()->json(['data' => array_map(
            fn (Course $c) => $this->serialize($c, withStats: true),
            $courses,
        )]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:courses,slug'],
            'course_code' => ['nullable', 'string', 'max:50', 'unique:courses,course_code'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:draft,published,archived'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'is_self_paced' => ['nullable', 'boolean'],
            'delivery_mode' => ['nullable', 'string', 'in:live,self_paced,hybrid'],
            'level' => ['nullable', 'string', 'in:beginner,intermediate,advanced'],
            'language' => ['nullable', 'string', 'max:10'],
            'duration_hours' => ['nullable', 'integer', 'min:0'],
            'target_audience' => ['nullable', 'string'],
            'prerequisites' => ['nullable', 'string'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
            'application_fee' => ['nullable', 'numeric', 'min:0'],
            'tuition_fee' => ['nullable', 'numeric', 'min:0'],
            'certificate_fee' => ['nullable', 'numeric', 'min:0'],
        ]);

        $validated['slug'] = $this->generateUniqueSlug($validated['slug'] ?? $validated['title']);

        $course = $this->courses->createCourse($validated, (int) $request->user()->id);

        foreach (['application_fee' => 'application', 'tuition_fee' => 'tuition', 'certificate_fee' => 'certificate'] as $field => $feeType) {
            if (isset($validated[$field]) && (float) $validated[$field] >= 0) {
                $this->courses->setFee($course, $feeType, (float) $validated[$field]);
            }
        }

        return response()->json(['data' => $this->serialize($course)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeManage();

        $course = $this->courses->findCourse($id);
        if ($course === null) {
            abort(404, 'Course not found.');
        }
        $this->authorizeCourseOwnership($course, $request->user());

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'unique:courses,slug,'.$id],
            'course_code' => ['sometimes', 'nullable', 'string', 'max:50', 'unique:courses,course_code,'.$id],
            'description' => ['sometimes', 'nullable', 'string'],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:draft,published,archived'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date'],
            'is_self_paced' => ['sometimes', 'boolean'],
            'delivery_mode' => ['sometimes', 'string', 'in:live,self_paced,hybrid'],
            'level' => ['sometimes', 'string', 'in:beginner,intermediate,advanced'],
            'language' => ['sometimes', 'string', 'max:10'],
            'duration_hours' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'target_audience' => ['sometimes', 'nullable', 'string'],
            'prerequisites' => ['sometimes', 'nullable', 'string'],
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['string'],
            'application_fee' => ['sometimes', 'numeric', 'min:0'],
            'tuition_fee' => ['sometimes', 'numeric', 'min:0'],
            'certificate_fee' => ['sometimes', 'numeric', 'min:0'],
        ]);

        if (isset($validated['slug'])) {
            $validated['slug'] = $this->generateUniqueSlug($validated['slug'], $id);
        }

        $course = $this->courses->updateCourse($course, $validated);

        foreach (['application_fee' => 'application', 'tuition_fee' => 'tuition', 'certificate_fee' => 'certificate'] as $field => $feeType) {
            if (isset($validated[$field]) && (float) $validated[$field] >= 0) {
                $this->courses->setFee($course, $feeType, (float) $validated[$field]);
            }
        }

        return response()->json(['data' => $this->serialize($course)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeManage();

        $course = $this->courses->findCourse($id);
        if ($course === null) {
            abort(404, 'Course not found.');
        }
        $this->authorizeCourseOwnership($course, $request->user());

        $this->courses->deleteCourse($course);

        return response()->json(['data' => null, 'message' => 'Course deleted.']);
    }

    public function schedules(int $id): JsonResponse
    {
        $course = $this->courses->findCourse($id);
        if ($course === null) {
            abort(404, 'Course not found.');
        }

        return response()->json(['data' => $this->courses->schedulesForCourse($id)]);
    }

    private function authorizeManage(): void
    {
        $user = request()->user();
        if ($user?->isAdmin() || $user?->isInstructor()) {
            return;
        }

        abort(403, 'Only admins and instructors can manage courses.');
    }

    /**
     * Slugify the input and ensure it is unique, appending -2, -3, etc.
     * on duplicates (including soft-deleted rows).
     */
    protected function generateUniqueSlug(string $value, ?int $ignoreId = null): string
    {
        $base = strtolower(trim($value));
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'course';
        }

        $candidate = $base;
        $suffix = 2;
        while ($this->slugExists($candidate, $ignoreId)) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    protected function slugExists(string $slug, ?int $ignoreId = null): bool
    {
        $query = Course::query()->withTrashed()->where('slug', $slug);
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        return $query->exists();
    }

    private function authorizeCourseOwnership(Course $course, ?\App\Models\User $user): void
    {
        if ($user === null) {
            abort(401);
        }

        // Admins manage any course; instructors only their own.
        if ($user->isAdmin()) {
            return;
        }

        if ((int) $course->created_by !== (int) $user->id) {
            abort(403, 'You can only manage courses you created.');
        }
    }

    private function serialize(Course $course, bool $withSchedule = false, ?User $user = null, bool $withStats = false): array
    {
        return [
            'id' => $course->id,
            'created_by' => $course->created_by,
            'enrollment_summary' => $withStats ? $this->enrollmentSummary($course) : null,
            'title' => $course->title,
            'slug' => $course->slug,
            'course_code' => $course->course_code,
            'description' => $course->description,
            'category' => $course->category,
            'cover_url' => $course->cover_url,
            'status' => $course->status,
            'start_date' => $course->start_date?->toIso8601String(),
            'end_date' => $course->end_date?->toIso8601String(),
            'is_self_paced' => $course->is_self_paced,
            'delivery_mode' => $course->delivery_mode,
            'level' => $course->level,
            'language' => $course->language,
            'duration_hours' => $course->duration_hours,
            'target_audience' => $course->target_audience,
            'prerequisites' => $course->prerequisites,
            'tags' => $course->tags,
            'enrollment' => $user ? $this->serializeUserEnrollment($course, (int) $user->id) : null,
            'fees' => $course->fees->map(fn ($fee) => [
                'fee_type' => $fee->fee_type,
                'amount' => (float) $fee->amount,
                'currency' => $fee->currency,
                'is_required' => $fee->is_required,
            ])->values(),
            'schedules' => $withSchedule
                ? $course->schedules->map(fn ($s) => [
                    'id' => $s->id,
                    'title' => $s->title,
                    'starts_at' => $s->starts_at?->toIso8601String(),
                    'ends_at' => $s->ends_at?->toIso8601String(),
                    'location' => $s->location,
                    'is_online' => $s->is_online,
                ])->values()
                : null,
        ];
    }

    /**
     * Enrollment counters for the management view. Uses the aggregate counts
     * loaded by the repository (withCount) and falls back to live queries when
     * a course was loaded without them (e.g. right after create/update).
     */
    private function enrollmentSummary(Course $course): array
    {
        $count = function (string $attribute, callable $fallback) use ($course): int {
            $value = $course->getAttribute($attribute);

            return $value !== null ? (int) $value : (int) $fallback();
        };

        $enrollments = fn () => $course->enrollments();

        return [
            'enrolled' => $count('enrolled_count', fn () => $enrollments()->whereNotIn('status', ['rejected', 'cancelled'])->count()),
            'pending_review' => $count('pending_review_count', fn () => $enrollments()->whereIn('status', ['applied', 'application_fee_paid'])->count()),
            'admitted' => $count('admitted_count', fn () => $enrollments()->where('status', 'admitted')->count()),
            'tuition_paid' => $count('tuition_paid_count', fn () => $enrollments()->whereHas('payments', fn ($p) => $p->where('fee_type', 'tuition')->where('status', 'paid'))->count()),
            'in_progress' => $count('in_progress_count', fn () => $enrollments()->whereIn('status', ['tuition_paid', 'in_progress'])->count()),
            'completed' => $count('completed_count', fn () => $enrollments()->whereIn('status', ['completed', 'certification', 'certified'])->count()),
            'certified' => $count('certified_count', fn () => $enrollments()->where('status', 'certified')->count()),
            'certificates_issued' => $count('certificates_issued_count', fn () => $course->certificates()->count()),
            'rejected' => $count('rejected_count', fn () => $enrollments()->where('status', 'rejected')->count()),
            'cancelled' => $count('cancelled_count', fn () => $enrollments()->where('status', 'cancelled')->count()),
        ];
    }

    private function serializeUserEnrollment(Course $course, int $userId): ?array
    {
        $enrollment = $course->enrollments()
            ->where('user_id', $userId)
            ->latest('id')
            ->first();

        if ($enrollment === null) {
            return null;
        }

        return [
            'id' => $enrollment->id,
            'course_id' => $enrollment->course_id,
            'status' => $enrollment->status,
            'applied_at' => $enrollment->applied_at?->toIso8601String(),
            'admitted_at' => $enrollment->admitted_at?->toIso8601String(),
            'completed_at' => $enrollment->completed_at?->toIso8601String(),
            'certified_at' => $enrollment->certified_at?->toIso8601String(),
            'has_progress' => $enrollment->status === Enrollment::STATUS_IN_PROGRESS,
        ];
    }
}