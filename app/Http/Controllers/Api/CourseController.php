<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
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
        $courses = $request->user()?->isAdmin()
            ? $this->courses->allCourses()
            : $this->courses->publishedCourses();

        return response()->json(['data' => array_map(
            fn (Course $c) => $this->serialize($c),
            $courses,
        )]);
    }

    public function show(int $id): JsonResponse
    {
        $course = $this->courses->findCourse($id);
        if ($course === null) {
            abort(404, 'Course not found.');
        }

        if (! $course->isPublished()) {
            abort(404, 'Course not found.');
        }

        return response()->json(['data' => $this->serialize($course, withSchedule: true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:courses,slug'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:draft,published,archived'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'is_self_paced' => ['nullable', 'boolean'],
            'application_fee' => ['nullable', 'numeric', 'min:0'],
            'tuition_fee' => ['nullable', 'numeric', 'min:0'],
            'certificate_fee' => ['nullable', 'numeric', 'min:0'],
        ]);

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

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'unique:courses,slug,'.$id],
            'description' => ['sometimes', 'nullable', 'string'],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:draft,published,archived'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date'],
            'is_self_paced' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $this->serialize($this->courses->updateCourse($course, $validated))]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeManage();

        $course = $this->courses->findCourse($id);
        if ($course === null) {
            abort(404, 'Course not found.');
        }

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
        if (! request()->user()?->isAdmin()) {
            abort(403, 'Only admins can manage courses.');
        }
    }

    private function serialize(Course $course, bool $withSchedule = false): array
    {
        return [
            'id' => $course->id,
            'title' => $course->title,
            'slug' => $course->slug,
            'description' => $course->description,
            'category' => $course->category,
            'cover_url' => $course->cover_url,
            'status' => $course->status,
            'start_date' => $course->start_date?->toIso8601String(),
            'end_date' => $course->end_date?->toIso8601String(),
            'is_self_paced' => $course->is_self_paced,
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
}