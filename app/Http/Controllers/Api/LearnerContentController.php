<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Submission;
use App\Repositories\Contracts\EnrollmentRepositoryInterface;
use App\Services\CourseContentService;
use App\Services\EnrollmentService;
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
        protected EnrollmentRepositoryInterface $enrollments,
        protected EnrollmentService $enrollmentService,
    ) {}

    /** Full course content for an enrolled learner (correct answers hidden). */
    public function content(Request $request, string|int $courseId): JsonResponse
    {
        $courseId = $this->courseKey($courseId);
        $this->requireEnrolled($courseId, $request->user());

        $course = $this->content->fullCourse($courseId);

        return response()->json([
            'data' => $this->serializeLearnerCourse($course),
        ]);
    }

    public function submit(Request $request, string|int $courseId, string $type, int $typeId): JsonResponse
    {
        $courseId = $this->courseKey($courseId);
        $this->requireEnrolled($courseId, $request->user());

        $validated = $request->validate([
            'content' => ['nullable', 'string'],
            'file' => ['nullable', 'file', 'max:10240'],
            'answers' => ['nullable', 'array'],
        ]);

        $filePath = null;
        if ($request->hasFile('file')) {
            $filePath = $request->file('file')->store('submissions', 'public');
        }

        $submission = $this->content->submitWork(
            $request->user(),
            $courseId,
            $type,
            $typeId,
            [
                'content' => $validated['content'] ?? null,
                'file_path' => $filePath,
                'answers' => $validated['answers'] ?? null,
            ],
        );

        $this->enrollmentService->refreshCompletionAfterProgress($request->user(), $courseId);

        return response()->json([
            'data' => $this->serializeSubmission($submission),
        ], 201);
    }

    public function submitAttempt(Request $request, string|int $courseId, string $type, int $typeId): JsonResponse
    {
        $courseId = $this->courseKey($courseId);
        $this->requireEnrolled($courseId, $request->user());

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

        $this->enrollmentService->refreshCompletionAfterProgress($request->user(), $courseId);

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

    public function markLesson(Request $request, string|int $courseId, int $lessonId): JsonResponse
    {
        $courseId = $this->courseKey($courseId);
        $this->requireEnrolled($courseId, $request->user());

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

        $this->enrollmentService->refreshCompletionAfterProgress($request->user(), $courseId);

        return response()->json([
            'data' => [
                'lesson_id' => $progress->lesson_id,
                'status' => $progress->status,
                'completed_at' => $progress->completed_at?->toIso8601String(),
            ],
        ]);
    }

    public function progress(Request $request, string|int $courseId): JsonResponse
    {
        $courseId = $this->courseKey($courseId);
        $this->requireEnrolled($courseId, $request->user());

        $progress = $this->content->courseProgress($request->user(), $courseId);

        return response()->json(['data' => $progress]);
    }

    /** Resolve a slug-or-id course key to its numeric id for scoping. */
    protected function courseKey(string|int $courseId): int
    {
        return Course::resolveByKeyOrFail($courseId)->id;
    }

    protected function requireEnrolled(string|int $courseId, $user): void
    {
        if ($user === null) {
            abort(401);
        }

        if ($user->isAdmin() || $user->isInstructor()) {
            return;
        }

        if ($this->enrollments->findByCourseAndUser($courseId, (int) $user->id) === null) {
            abort(403, 'You must be enrolled in this course.');
        }
    }

    protected function serializeLearnerCourse(\App\Models\Course $course): array
    {
        return [
            'id' => $course->id,
            'title' => $course->title,
            'slug' => $course->slug,
            'description' => $course->description,
            'category' => $course->category,
            'level' => $course->level,
            'delivery_mode' => $course->delivery_mode,
            'is_self_paced' => $course->is_self_paced,
            'sections' => $course->sections->map(fn ($s) => [
                'id' => $s->id,
                'title' => $s->title,
                'description' => $s->description,
                'sort_order' => $s->sort_order,
                'lessons' => $s->lessons->map(fn ($l) => [
                    'id' => $l->id,
                    'section_id' => $l->section_id,
                    'title' => $l->title,
                    'content_type' => $l->content_type,
                    'content' => $l->content,
                    'video_url' => $l->video_url,
                    'duration_minutes' => $l->duration_minutes,
                    'sort_order' => $l->sort_order,
                    'is_free_preview' => $l->is_free_preview,
                ])->values(),
            ])->values(),
            'learning_outcomes' => $course->learningOutcomes->map(fn ($o) => [
                'id' => $o->id,
                'description' => $o->description,
            ])->values(),
            'resources' => $course->resources->map(fn ($r) => [
                'id' => $r->id,
                'title' => $r->title,
                'type' => $r->type,
                'url' => $r->url,
                'file_path' => $r->file_path,
                'description' => $r->description,
            ])->values(),
            'quizzes' => $course->quizzes->map(fn ($q) => [
                'id' => $q->id,
                'title' => $q->title,
                'description' => $q->description,
                'passing_score' => $q->passing_score,
                'time_limit_minutes' => $q->time_limit_minutes,
                'questions' => $q->questions->map(fn ($question) => [
                    'id' => $question->id,
                    'question' => $question->question,
                    'type' => $question->type,
                    'options' => $question->options,
                    'points' => $question->points,
                ])->values(),
            ])->values(),
            'exercises' => $course->exercises->map(fn ($e) => [
                'id' => $e->id,
                'title' => $e->title,
                'instructions' => $e->instructions,
                'file_path' => $e->file_path,
                'type' => $e->type,
                'max_score' => $e->max_score,
                'passing_score' => $e->passing_score,
                'questions' => $e->questions->map(fn ($question) => [
                    'id' => $question->id,
                    'question' => $question->question,
                    'type' => $question->type,
                    'options' => $question->options,
                    'points' => $question->points,
                ])->values(),
            ])->values(),
            'exams' => $course->exams->map(fn ($x) => [
                'id' => $x->id,
                'title' => $x->title,
                'description' => $x->description,
                'file_path' => $x->file_path,
                'max_score' => $x->max_score,
                'passing_score' => $x->passing_score,
                'time_limit_minutes' => $x->time_limit_minutes,
                'questions' => $x->questions->map(fn ($question) => [
                    'id' => $question->id,
                    'question' => $question->question,
                    'type' => $question->type,
                    'options' => $question->options,
                    'points' => $question->points,
                ])->values(),
            ])->values(),
            'assignments' => $course->assignments->map(fn ($a) => [
                'id' => $a->id,
                'title' => $a->title,
                'instructions' => $a->instructions,
                'submission_type' => $a->submission_type,
                'max_score' => $a->max_score,
            ])->values(),
        ];
    }

    protected function serializeSubmission(Submission $submission): array
    {
        return [
            'id' => $submission->id,
            'status' => $submission->status,
            'content' => $submission->content,
            'file_path' => $submission->file_path,
            'score' => $submission->score,
            'max_score' => $submission->max_score,
            'feedback' => $submission->feedback,
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'graded_at' => $submission->graded_at?->toIso8601String(),
        ];
    }
}