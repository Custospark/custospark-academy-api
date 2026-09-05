<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\Submission;
use App\Services\CourseContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Learner-facing course actions: submissions, assessment attempts and
 * lesson progress tracking.
 */
class LearnerContentController extends Controller
{
    public function __construct(
        protected CourseContentService $content,
    ) {}

    public function submit(Request $request, int $courseId, string $type, int $typeId): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['nullable', 'string'],
            'file_path' => ['nullable', 'string'],
            'answers' => ['nullable', 'array'],
        ]);

        $submission = $this->content->submitWork(
            $request->user(),
            $courseId,
            $type,
            $typeId,
            $validated,
        );

        return response()->json([
            'data' => $this->serializeSubmission($submission),
        ], 201);
    }

    public function submitAttempt(Request $request, int $courseId, string $type, int $typeId): JsonResponse
    {
        $validated = $request->validate([
            'answers' => ['required', 'array'],
        ]);

        $attempt = $this->content->submitAssessmentAttempt(
            $request->user(),
            $courseId,
            $type,
            $typeId,
            $validated['answers'],
        );

        return response()->json([
            'data' => [
                'id' => $attempt->id,
                'score' => $attempt->score,
                'max_score' => $attempt->max_score,
                'is_passed' => $attempt->is_passed,
                'submitted_at' => $attempt->submitted_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function markLesson(Request $request, int $courseId, int $lessonId): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:not_started,in_progress,completed'],
        ]);

        $lesson = Lesson::query()->where('course_id', $courseId)->findOrFail($lessonId);

        $progress = $this->content->markLessonProgress(
            $request->user(),
            $courseId,
            $lesson,
            $validated['status'],
        );

        return response()->json([
            'data' => [
                'lesson_id' => $progress->lesson_id,
                'status' => $progress->status,
                'completed_at' => $progress->completed_at?->toIso8601String(),
            ],
        ]);
    }

    public function progress(Request $request, int $courseId): JsonResponse
    {
        $progress = $this->content->courseProgress($request->user(), $courseId);

        return response()->json(['data' => $progress]);
    }

    protected function serializeSubmission(Submission $submission): array
    {
        return [
            'id' => $submission->id,
            'status' => $submission->status,
            'score' => $submission->score,
            'max_score' => $submission->max_score,
            'feedback' => $submission->feedback,
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'graded_at' => $submission->graded_at?->toIso8601String(),
        ];
    }
}