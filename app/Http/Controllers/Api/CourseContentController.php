<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Exam;
use App\Models\Exercise;
use App\Models\LearningOutcome;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\Resource;
use App\Services\CourseContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CourseContentController extends Controller
{
    public function __construct(
        protected CourseContentService $content,
    ) {}

    /* --------------------------- Full structure -------------------------- */

    public function show(Request $request, int $courseId): JsonResponse
    {
        $course = $this->content->fullCourse($courseId);
        $this->authorizeCourse($course, $request->user());

        return response()->json(['data' => $this->serializeFull($course)]);
    }

    public function gradeSubmission(Request $request, int $courseId, int $submissionId): JsonResponse
    {
        $course = Course::query()->findOrFail($courseId);
        $this->authorizeCourse($course, $request->user());

        $validated = $request->validate([
            'score' => ['required', 'integer', 'min:0'],
            'feedback' => ['nullable', 'string'],
        ]);

        $submission = $this->content->gradeSubmission($submissionId, (int) $request->user()->id, $validated);

        return response()->json([
            'data' => [
                'id' => $submission->id,
                'status' => $submission->status,
                'score' => $submission->score,
                'feedback' => $submission->feedback,
                'graded_at' => $submission->graded_at?->toIso8601String(),
            ],
        ]);
    }

    /* ------------------------------ Sections ----------------------------- */

    public function storeSection(Request $request, int $courseId): JsonResponse
    {
        $course = Course::query()->findOrFail($courseId);
        $this->authorizeCourse($course, $request->user());

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'data' => $this->serializeSection($this->content->createSection($courseId, $validated)),
        ], 201);
    }

    public function updateSection(Request $request, int $courseId, int $sectionId): JsonResponse
    {
        $section = $this->requireSection($courseId, $sectionId);
        $this->authorizeCourse($section->course, $request->user());

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'sort_order' => ['sometimes', 'integer'],
        ]);

        return response()->json([
            'data' => $this->serializeSection($this->content->updateSection($section, $validated)),
        ]);
    }

    public function destroySection(Request $request, int $courseId, int $sectionId): JsonResponse
    {
        $section = $this->requireSection($courseId, $sectionId);
        $this->authorizeCourse($section->course, $request->user());

        $this->content->deleteSection($section);

        return response()->json(['data' => null, 'message' => 'Section deleted.']);
    }

    /* ------------------------------ Lessons ------------------------------- */

    public function storeLesson(Request $request, int $courseId): JsonResponse
    {
        $course = Course::query()->findOrFail($courseId);
        $this->authorizeCourse($course, $request->user());

        $validated = $this->validateLesson($request);

        return response()->json([
            'data' => $this->serializeLesson($this->content->createLesson($courseId, $validated)),
        ], 201);
    }

    public function updateLesson(Request $request, int $courseId, int $lessonId): JsonResponse
    {
        $lesson = $this->requireLesson($courseId, $lessonId);
        $this->authorizeCourse($lesson->course, $request->user());

        $validated = $this->validateLesson($request, true);

        return response()->json([
            'data' => $this->serializeLesson($this->content->updateLesson($lesson, $validated)),
        ]);
    }

    public function destroyLesson(Request $request, int $courseId, int $lessonId): JsonResponse
    {
        $lesson = $this->requireLesson($courseId, $lessonId);
        $this->authorizeCourse($lesson->course, $request->user());

        $this->content->deleteLesson($lesson);

        return response()->json(['data' => null, 'message' => 'Lesson deleted.']);
    }

    /* ------------------------------ Outcomes ------------------------------ */

    public function storeOutcome(Request $request, int $courseId): JsonResponse
    {
        $course = Course::query()->findOrFail($courseId);
        $this->authorizeCourse($course, $request->user());

        $validated = $request->validate([
            'description' => ['required', 'string'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'data' => $this->serializeOutcome($this->content->createOutcome($courseId, $validated)),
        ], 201);
    }

    public function destroyOutcome(Request $request, int $courseId, int $outcomeId): JsonResponse
    {
        $outcome = LearningOutcome::query()->where('course_id', $courseId)->findOrFail($outcomeId);
        $this->authorizeCourse($outcome->course, $request->user());

        $this->content->deleteOutcome($outcome);

        return response()->json(['data' => null, 'message' => 'Outcome removed.']);
    }

    /* ------------------------------ Resources ----------------------------- */

    public function storeResource(Request $request, int $courseId): JsonResponse
    {
        $course = Course::query()->findOrFail($courseId);
        $this->authorizeCourse($course, $request->user());

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:book,link,video,file,article'],
            'url' => ['nullable', 'string'],
            'file' => ['nullable', 'file', 'max:20480'],
            'description' => ['nullable', 'string'],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $filePath = null;
        if ($request->hasFile('file')) {
            $filePath = $request->file('file')->store('resources', 'public');
        }

        return response()->json([
            'data' => $this->serializeResource($this->content->createResource($courseId, [
                ...$validated,
                'file_path' => $filePath ?? $validated['url'] ?? null,
            ])),
        ], 201);
    }

    public function updateResource(Request $request, int $courseId, int $resourceId): JsonResponse
    {
        $resource = Resource::query()->where('course_id', $courseId)->findOrFail($resourceId);
        $this->authorizeCourse($resource->course, $request->user());

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'in:book,link,video,file,article'],
            'url' => ['sometimes', 'nullable', 'string'],
            'file' => ['sometimes', 'file', 'max:20480'],
            'description' => ['sometimes', 'nullable', 'string'],
            'lesson_id' => ['sometimes', 'nullable', 'integer', 'exists:lessons,id'],
            'sort_order' => ['sometimes', 'integer'],
        ]);

        if ($request->hasFile('file')) {
            $validated['file_path'] = $request->file('file')->store('resources', 'public');
        }

        return response()->json([
            'data' => $this->serializeResource($this->content->updateResource($resource, $validated)),
        ]);
    }

    public function destroyResource(Request $request, int $courseId, int $resourceId): JsonResponse
    {
        $resource = Resource::query()->where('course_id', $courseId)->findOrFail($resourceId);
        $this->authorizeCourse($resource->course, $request->user());

        $this->content->deleteResource($resource);

        return response()->json(['data' => null, 'message' => 'Resource removed.']);
    }

    /* ------------------------------- Quizzes ------------------------------ */

    public function storeQuiz(Request $request, int $courseId): JsonResponse
    {
        $course = Course::query()->findOrFail($courseId);
        $this->authorizeCourse($course, $request->user());

        $validated = $this->validateQuiz($request);

        return response()->json([
            'data' => $this->serializeQuiz($this->content->createQuiz($courseId, $validated)),
        ], 201);
    }

    public function updateQuiz(Request $request, int $courseId, int $quizId): JsonResponse
    {
        $quiz = Quiz::query()->where('course_id', $courseId)->findOrFail($quizId);
        $this->authorizeCourse($quiz->course, $request->user());

        $validated = $this->validateQuiz($request, true);

        return response()->json([
            'data' => $this->serializeQuiz($this->content->updateQuiz($quiz, $validated)),
        ]);
    }

    public function destroyQuiz(Request $request, int $courseId, int $quizId): JsonResponse
    {
        $quiz = Quiz::query()->where('course_id', $courseId)->findOrFail($quizId);
        $this->authorizeCourse($quiz->course, $request->user());

        $this->content->deleteQuiz($quiz);

        return response()->json(['data' => null, 'message' => 'Quiz deleted.']);
    }

    /* ------------------------------ Exercises ----------------------------- */

    public function storeExercise(Request $request, int $courseId): JsonResponse
    {
        $course = Course::query()->findOrFail($courseId);
        $this->authorizeCourse($course, $request->user());

        $validated = $this->validateAssessment($request);

        return response()->json([
            'data' => $this->serializeExercise($this->content->createExercise($courseId, $validated)),
        ], 201);
    }

    public function updateExercise(Request $request, int $courseId, int $exerciseId): JsonResponse
    {
        $exercise = Exercise::query()->where('course_id', $courseId)->findOrFail($exerciseId);
        $this->authorizeCourse($exercise->course, $request->user());

        $validated = $this->validateAssessment($request, true);

        return response()->json([
            'data' => $this->serializeExercise($this->content->updateExercise($exercise, $validated)),
        ]);
    }

    public function destroyExercise(Request $request, int $courseId, int $exerciseId): JsonResponse
    {
        $exercise = Exercise::query()->where('course_id', $courseId)->findOrFail($exerciseId);
        $this->authorizeCourse($exercise->course, $request->user());

        $this->content->deleteExercise($exercise);

        return response()->json(['data' => null, 'message' => 'Exercise deleted.']);
    }

    /* -------------------------------- Exams ------------------------------- */

    public function storeExam(Request $request, int $courseId): JsonResponse
    {
        $course = Course::query()->findOrFail($courseId);
        $this->authorizeCourse($course, $request->user());

        $validated = $this->validateAssessment($request);

        return response()->json([
            'data' => $this->serializeExam($this->content->createExam($courseId, $validated)),
        ], 201);
    }

    public function updateExam(Request $request, int $courseId, int $examId): JsonResponse
    {
        $exam = Exam::query()->where('course_id', $courseId)->findOrFail($examId);
        $this->authorizeCourse($exam->course, $request->user());

        $validated = $this->validateAssessment($request, true);

        return response()->json([
            'data' => $this->serializeExam($this->content->updateExam($exam, $validated)),
        ]);
    }

    public function destroyExam(Request $request, int $courseId, int $examId): JsonResponse
    {
        $exam = Exam::query()->where('course_id', $courseId)->findOrFail($examId);
        $this->authorizeCourse($exam->course, $request->user());

        $this->content->deleteExam($exam);

        return response()->json(['data' => null, 'message' => 'Exam deleted.']);
    }

    /* ----------------------------- Assignments ---------------------------- */

    public function storeAssignment(Request $request, int $courseId): JsonResponse
    {
        $course = Course::query()->findOrFail($courseId);
        $this->authorizeCourse($course, $request->user());

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string'],
            'submission_type' => ['nullable', 'string', 'in:text,file,link'],
            'due_after_days' => ['nullable', 'integer', 'min:0'],
            'max_score' => ['nullable', 'integer', 'min:0'],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            'sort_order' => ['nullable', 'integer'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        return response()->json([
            'data' => $this->serializeAssignment($this->content->createAssignment($courseId, $validated)),
        ], 201);
    }

    public function updateAssignment(Request $request, int $courseId, int $assignmentId): JsonResponse
    {
        $assignment = Assignment::query()->where('course_id', $courseId)->findOrFail($assignmentId);
        $this->authorizeCourse($assignment->course, $request->user());

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'instructions' => ['sometimes', 'nullable', 'string'],
            'submission_type' => ['sometimes', 'string', 'in:text,file,link'],
            'due_after_days' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_score' => ['sometimes', 'integer', 'min:0'],
            'lesson_id' => ['sometimes', 'nullable', 'integer', 'exists:lessons,id'],
            'sort_order' => ['sometimes', 'integer'],
            'is_published' => ['sometimes', 'boolean'],
        ]);

        return response()->json([
            'data' => $this->serializeAssignment($this->content->updateAssignment($assignment, $validated)),
        ]);
    }

    public function destroyAssignment(Request $request, int $courseId, int $assignmentId): JsonResponse
    {
        $assignment = Assignment::query()->where('course_id', $courseId)->findOrFail($assignmentId);
        $this->authorizeCourse($assignment->course, $request->user());

        $this->content->deleteAssignment($assignment);

        return response()->json(['data' => null, 'message' => 'Assignment deleted.']);
    }

    /* ---------------------------- Authorization --------------------------- */

    protected function authorizeCourse(Course $course, $user): void
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
        abort(403, 'You can only manage courses you created.');
    }

    protected function requireSection(int $courseId, int $sectionId): CourseSection
    {
        return CourseSection::query()->where('course_id', $courseId)->findOrFail($sectionId);
    }

    protected function requireLesson(int $courseId, int $lessonId): Lesson
    {
        return Lesson::query()->where('course_id', $courseId)->findOrFail($lessonId);
    }

    protected function validateLesson(Request $request, bool $partial = false): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'section_id' => ['nullable', 'integer', 'exists:course_sections,id'],
            'content_type' => ['nullable', 'string', 'in:text,video,article,embed'],
            'content' => ['nullable', 'string'],
            'video_url' => ['nullable', 'string'],
            'duration_minutes' => ['nullable', 'integer', 'min:0'],
            'sort_order' => ['nullable', 'integer'],
            'is_free_preview' => ['nullable', 'boolean'],
            'is_published' => ['nullable', 'boolean'],
        ];

        if ($partial) {
            $rules = collect($rules)->mapWithKeys(fn ($rule, $key) => ['sometimes'.substr($key, 0) => $rule])->all();
        }

        return $request->validate($rules);
    }

    protected function validateQuiz(Request $request, bool $partial = false): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'passing_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'time_limit_minutes' => ['nullable', 'integer', 'min:0'],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            'sort_order' => ['nullable', 'integer'],
            'is_published' => ['nullable', 'boolean'],
            'questions' => ['nullable', 'array'],
            'questions.*.question' => ['required_with:questions', 'string'],
            'questions.*.type' => ['nullable', 'string', 'in:multiple_choice,true_false,short_answer'],
            'questions.*.options' => ['nullable', 'array'],
            'questions.*.correct_answer' => ['nullable', 'string'],
            'questions.*.points' => ['nullable', 'integer', 'min:1'],
        ];

        if ($partial) {
            $rules = collect($rules)->mapWithKeys(fn ($rule, $key) => ['sometimes'.$key => $rule])->all();
        }

        return $request->validate($rules);
    }

    protected function validateAssessment(Request $request, bool $partial = false): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'instructions' => ['nullable', 'string'],
            'max_score' => ['nullable', 'integer', 'min:0'],
            'passing_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'time_limit_minutes' => ['nullable', 'integer', 'min:0'],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            'sort_order' => ['nullable', 'integer'],
            'is_published' => ['nullable', 'boolean'],
            'questions' => ['nullable', 'array'],
            'questions.*.question' => ['required_with:questions', 'string'],
            'questions.*.type' => ['nullable', 'string'],
            'questions.*.options' => ['nullable', 'array'],
            'questions.*.correct_answer' => ['nullable', 'string'],
            'questions.*.points' => ['nullable', 'integer', 'min:1'],
        ];

        if ($partial) {
            $rules = collect($rules)->mapWithKeys(fn ($rule, $key) => ['sometimes'.$key => $rule])->all();
        }

        return $request->validate($rules);
    }

    /* ------------------------------ Serialize ---------------------------- */

    protected function serializeFull(Course $course): array
    {
        return [
            'id' => $course->id,
            'title' => $course->title,
            'slug' => $course->slug,
            'description' => $course->description,
            'category' => $course->category,
            'cover_url' => $course->cover_url,
            'status' => $course->status,
            'level' => $course->level,
            'language' => $course->language,
            'duration_hours' => $course->duration_hours,
            'target_audience' => $course->target_audience,
            'prerequisites' => $course->prerequisites,
            'tags' => $course->tags,
            'delivery_mode' => $course->delivery_mode,
            'is_self_paced' => $course->is_self_paced,
            'start_date' => $course->start_date?->toIso8601String(),
            'end_date' => $course->end_date?->toIso8601String(),
            'sections' => $course->sections->map(fn ($s) => [
                'id' => $s->id,
                'title' => $s->title,
                'description' => $s->description,
                'sort_order' => $s->sort_order,
                'lessons' => $s->lessons->map(fn ($l) => $this->serializeLesson($l))->values(),
            ])->values(),
            'learning_outcomes' => $course->learningOutcomes->map(fn ($o) => $this->serializeOutcome($o))->values(),
            'resources' => $course->resources->map(fn ($r) => $this->serializeResource($r))->values(),
            'quizzes' => $course->quizzes->map(fn ($q) => $this->serializeQuiz($q))->values(),
            'exercises' => $course->exercises->map(fn ($e) => $this->serializeExercise($e))->values(),
            'exams' => $course->exams->map(fn ($x) => $this->serializeExam($x))->values(),
            'assignments' => $course->assignments->map(fn ($a) => $this->serializeAssignment($a))->values(),
            'fees' => $course->fees->map(fn ($fee) => [
                'fee_type' => $fee->fee_type,
                'amount' => (float) $fee->amount,
                'currency' => $fee->currency,
            ])->values(),
        ];
    }

    protected function serializeSection(CourseSection $section): array
    {
        return [
            'id' => $section->id,
            'course_id' => $section->course_id,
            'title' => $section->title,
            'description' => $section->description,
            'sort_order' => $section->sort_order,
        ];
    }

    protected function serializeLesson(Lesson $lesson): array
    {
        return [
            'id' => $lesson->id,
            'course_id' => $lesson->course_id,
            'section_id' => $lesson->section_id,
            'title' => $lesson->title,
            'content_type' => $lesson->content_type,
            'content' => $lesson->content,
            'video_url' => $lesson->video_url,
            'duration_minutes' => $lesson->duration_minutes,
            'sort_order' => $lesson->sort_order,
            'is_free_preview' => $lesson->is_free_preview,
            'is_published' => $lesson->is_published,
            'resources' => $lesson->resources->map(fn ($r) => $this->serializeResource($r))->values(),
        ];
    }

    protected function serializeOutcome(LearningOutcome $outcome): array
    {
        return [
            'id' => $outcome->id,
            'description' => $outcome->description,
            'sort_order' => $outcome->sort_order,
        ];
    }

    protected function serializeResource(Resource $resource): array
    {
        return [
            'id' => $resource->id,
            'course_id' => $resource->course_id,
            'lesson_id' => $resource->lesson_id,
            'title' => $resource->title,
            'type' => $resource->type,
            'url' => $resource->url,
            'file_path' => $resource->file_path,
            'description' => $resource->description,
            'sort_order' => $resource->sort_order,
        ];
    }

    protected function serializeQuiz(Quiz $quiz): array
    {
        return [
            'id' => $quiz->id,
            'course_id' => $quiz->course_id,
            'lesson_id' => $quiz->lesson_id,
            'title' => $quiz->title,
            'description' => $quiz->description,
            'passing_score' => $quiz->passing_score,
            'time_limit_minutes' => $quiz->time_limit_minutes,
            'is_published' => $quiz->is_published,
            'questions' => $quiz->questions->map(fn ($q) => $this->serializeQuestion($q, 'quiz'))->values(),
        ];
    }

    protected function serializeExercise(Exercise $exercise): array
    {
        return [
            'id' => $exercise->id,
            'course_id' => $exercise->course_id,
            'lesson_id' => $exercise->lesson_id,
            'title' => $exercise->title,
            'instructions' => $exercise->instructions,
            'type' => $exercise->type,
            'max_score' => $exercise->max_score,
            'passing_score' => $exercise->passing_score,
            'time_limit_minutes' => $exercise->time_limit_minutes,
            'is_published' => $exercise->is_published,
            'questions' => $exercise->questions->map(fn ($q) => $this->serializeQuestion($q, 'exercise'))->values(),
        ];
    }

    protected function serializeExam(Exam $exam): array
    {
        return [
            'id' => $exam->id,
            'course_id' => $exam->course_id,
            'title' => $exam->title,
            'description' => $exam->description,
            'max_score' => $exam->max_score,
            'passing_score' => $exam->passing_score,
            'time_limit_minutes' => $exam->time_limit_minutes,
            'is_published' => $exam->is_published,
            'questions' => $exam->questions->map(fn ($q) => $this->serializeQuestion($q, 'exam'))->values(),
        ];
    }

    protected function serializeQuestion($question, string $type): array
    {
        return [
            'id' => $question->id,
            'question' => $question->question,
            'type' => $question->type,
            'options' => $question->options,
            'correct_answer' => $question->correct_answer,
            'points' => $question->points,
            'explanation' => $question->explanation,
            'sort_order' => $question->sort_order,
        ];
    }

    protected function serializeAssignment(Assignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'course_id' => $assignment->course_id,
            'lesson_id' => $assignment->lesson_id,
            'title' => $assignment->title,
            'instructions' => $assignment->instructions,
            'submission_type' => $assignment->submission_type,
            'due_after_days' => $assignment->due_after_days,
            'max_score' => $assignment->max_score,
            'is_published' => $assignment->is_published,
        ];
    }
}