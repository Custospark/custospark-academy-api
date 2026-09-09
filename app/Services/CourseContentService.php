<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AssessmentAttempt;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Submission;
use App\Models\User;
use App\Repositories\Contracts\CourseContentRepositoryInterface;
use App\Repositories\Contracts\SubmissionRepositoryInterface;
use App\Repositories\Contracts\CourseRepositoryInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Owns course-content operations (sections, lessons, outcomes, resources,
 * quizzes, exercises, exams, assignments) plus grading and progress.
 */
class CourseContentService
{
    public function __construct(
        protected CourseContentRepositoryInterface $content,
        protected SubmissionRepositoryInterface $submissions,
        protected CourseRepositoryInterface $courses,
        protected CourseCompletionService $completion,
    ) {}

    /** Full course structure for the builder. */
    /** Full builder structure, by slug or numeric id. */
    public function fullCourse(string|int $key): Course
    {
        $id = $key;
        if (! ctype_digit((string) $key)) {
            $id = Course::query()->where('slug', (string) $key)->value('id');
        }

        return Course::query()
            ->with([
                'sections.lessons',
                'learningOutcomes',
                'resources',
                'quizzes.questions',
                'exercises.questions',
                'exams.questions',
                'assignments',
                'fees',
            ])
            ->findOrFail($id ?? 0);
    }

    /* ------------------------------ Sections ------------------------------ */

    public function createSection(int $courseId, array $data): \App\Models\CourseSection
    {
        return $this->content->createSection([
            'course_id' => $courseId,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'sort_order' => $data['sort_order'] ?? $this->nextSort(\App\Models\CourseSection::class, $courseId),
        ]);
    }

    public function updateSection(\App\Models\CourseSection $section, array $data): \App\Models\CourseSection
    {
        return $this->content->updateSection($section, $data);
    }

    public function deleteSection(\App\Models\CourseSection $section): void
    {
        $this->content->deleteSection($section);
    }

    /* ------------------------------ Lessons ------------------------------- */

    public function createLesson(int $courseId, array $data): Lesson
    {
        return $this->content->createLesson([
            'course_id' => $courseId,
            'section_id' => $data['section_id'] ?? null,
            'title' => $data['title'],
            'content_type' => $data['content_type'] ?? Lesson::TYPE_TEXT,
            'content' => $data['content'] ?? null,
            'video_url' => $data['video_url'] ?? null,
            'video_path' => $data['video_path'] ?? null,
            'book_path' => $data['book_path'] ?? null,
            'duration_minutes' => $data['duration_minutes'] ?? null,
            'sort_order' => $data['sort_order'] ?? $this->nextSort(Lesson::class, $courseId),
            'is_free_preview' => $data['is_free_preview'] ?? false,
            'is_published' => $data['is_published'] ?? true,
        ]);
    }

    public function updateLesson(Lesson $lesson, array $data): Lesson
    {
        $oldVideo = $lesson->video_path;
        $oldBook = $lesson->book_path;
        $updated = $this->content->updateLesson($lesson, $data);
        if (array_key_exists('video_path', $data) && $data['video_path'] !== $oldVideo) {
            $this->deletePublicFile($oldVideo);
        }
        if (array_key_exists('book_path', $data) && $data['book_path'] !== $oldBook) {
            $this->deletePublicFile($oldBook);
        }

        return $updated;
    }

    public function deleteLesson(Lesson $lesson): void
    {
        $this->deletePublicFile($lesson->video_path);
        $this->deletePublicFile($lesson->book_path);
        $this->content->deleteLesson($lesson);
    }

    /* ----------------------------- Outcomes ------------------------------- */

    public function createOutcome(int $courseId, array $data): \App\Models\LearningOutcome
    {
        return $this->content->createOutcome([
            'course_id' => $courseId,
            'description' => $data['description'],
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }

    public function updateOutcome(\App\Models\LearningOutcome $outcome, array $data): \App\Models\LearningOutcome
    {
        return $this->content->updateOutcome($outcome, $data);
    }

    public function deleteOutcome(\App\Models\LearningOutcome $outcome): void
    {
        $this->content->deleteOutcome($outcome);
    }

    /* ----------------------------- Resources ------------------------------ */

    public function createResource(int $courseId, array $data): \App\Models\Resource
    {
        return $this->content->createResource([
            'course_id' => $courseId,
            'lesson_id' => $data['lesson_id'] ?? null,
            'title' => $data['title'],
            'type' => $data['type'] ?? \App\Models\Resource::TYPE_LINK,
            'url' => $data['url'] ?? null,
            'file_path' => $data['file_path'] ?? null,
            'description' => $data['description'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }

    public function updateResource(\App\Models\Resource $resource, array $data): \App\Models\Resource
    {
        $old = $resource->file_path;
        $updated = $this->content->updateResource($resource, $data);
        if (array_key_exists('file_path', $data) && $data['file_path'] !== $old) {
            $this->deletePublicFile($old);
        }

        return $updated;
    }

    public function deleteResource(\App\Models\Resource $resource): void
    {
        $this->deletePublicFile($resource->file_path);
        $this->content->deleteResource($resource);
    }

    /** Remove a file from the public disk (guards remote URLs + nulls). */
    protected function deletePublicFile(?string $path): void
    {
        if ($path !== null && $path !== '' && ! str_starts_with($path, 'http')) {
            Storage::disk('public')->delete($path);
        }
    }


    /* ------------------------------- Quizzes ------------------------------ */

    public function createQuiz(int $courseId, array $data): \App\Models\Quiz
    {
        $quiz = $this->content->createQuiz([
            'course_id' => $courseId,
            'lesson_id' => $data['lesson_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'passing_score' => $data['passing_score'] ?? 50,
            'max_attempts' => $data['max_attempts'] ?? 2,
            'time_limit_minutes' => $data['time_limit_minutes'] ?? null,
            'opens_at' => $data['opens_at'] ?? null,
            'closes_at' => $data['closes_at'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_published' => $data['is_published'] ?? false,
        ]);

        foreach ($data['questions'] ?? [] as $question) {
            $this->createQuizQuestion($quiz, $question);
        }

        return $quiz->fresh(['questions']);
    }

    public function createQuizQuestion(\App\Models\Quiz $quiz, array $data): \App\Models\QuizQuestion
    {
        return \App\Models\QuizQuestion::query()->create([
            'quiz_id' => $quiz->id,
            'question' => $data['question'],
            'type' => $data['type'] ?? \App\Models\QuizQuestion::TYPE_MULTIPLE_CHOICE,
            'options' => $data['options'] ?? null,
            'correct_answer' => $data['correct_answer'] ?? null,
            'points' => $data['points'] ?? 1,
            'explanation' => $data['explanation'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }

    public function updateQuiz(\App\Models\Quiz $quiz, array $data): \App\Models\Quiz
    {
        return $this->content->updateQuiz($quiz, $data);
    }

    public function deleteQuiz(\App\Models\Quiz $quiz): void
    {
        $this->content->deleteQuiz($quiz);
    }

    /* ----------------------------- Exercises ------------------------------ */

    public function createExercise(int $courseId, array $data): \App\Models\Exercise
    {
        $exercise = $this->content->createExercise([
            'course_id' => $courseId,
            'lesson_id' => $data['lesson_id'] ?? null,
            'title' => $data['title'],
            'instructions' => $data['instructions'] ?? null,
            'file_path' => $data['file_path'] ?? null,
            'type' => $data['type'] ?? \App\Models\Exercise::TYPE_QUIZ,
            'max_score' => $data['max_score'] ?? 100,
            'passing_score' => $data['passing_score'] ?? 50,
            'max_attempts' => $data['max_attempts'] ?? 2,
            'time_limit_minutes' => $data['time_limit_minutes'] ?? null,
            'opens_at' => $data['opens_at'] ?? null,
            'closes_at' => $data['closes_at'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_published' => $data['is_published'] ?? false,
        ]);

        foreach ($data['questions'] ?? [] as $question) {
            $this->createExerciseQuestion($exercise, $question);
        }

        return $exercise->fresh(['questions']);
    }

    public function createExerciseQuestion(\App\Models\Exercise $exercise, array $data): \App\Models\ExerciseQuestion
    {
        return \App\Models\ExerciseQuestion::query()->create([
            'exercise_id' => $exercise->id,
            'question' => $data['question'],
            'type' => $data['type'] ?? \App\Models\ExerciseQuestion::TYPE_MULTIPLE_CHOICE,
            'options' => $data['options'] ?? null,
            'correct_answer' => $data['correct_answer'] ?? null,
            'points' => $data['points'] ?? 1,
            'explanation' => $data['explanation'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }

    public function updateExercise(\App\Models\Exercise $exercise, array $data): \App\Models\Exercise
    {
        $old = $exercise->file_path;
        $updated = $this->content->updateExercise($exercise, $data);
        if (array_key_exists('file_path', $data) && $data['file_path'] !== $old) {
            $this->deletePublicFile($old);
        }

        return $updated;
    }

    public function deleteExercise(\App\Models\Exercise $exercise): void
    {
        $this->deletePublicFile($exercise->file_path);
        $this->content->deleteExercise($exercise);
    }

    /* -------------------------------- Exams ------------------------------- */

    public function createExam(int $courseId, array $data): \App\Models\Exam
    {
        $exam = $this->content->createExam([
            'course_id' => $courseId,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'file_path' => $data['file_path'] ?? null,
            'max_score' => $data['max_score'] ?? 100,
            'passing_score' => $data['passing_score'] ?? 50,
            'time_limit_minutes' => $data['time_limit_minutes'] ?? null,
            'opens_at' => $data['opens_at'] ?? null,
            'closes_at' => $data['closes_at'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_published' => $data['is_published'] ?? false,
        ]);

        foreach ($data['questions'] ?? [] as $question) {
            $this->createExamQuestion($exam, $question);
        }

        return $exam->fresh(['questions']);
    }

    public function createExamQuestion(\App\Models\Exam $exam, array $data): \App\Models\ExamQuestion
    {
        return \App\Models\ExamQuestion::query()->create([
            'exam_id' => $exam->id,
            'question' => $data['question'],
            'type' => $data['type'] ?? \App\Models\ExamQuestion::TYPE_MULTIPLE_CHOICE,
            'options' => $data['options'] ?? null,
            'correct_answer' => $data['correct_answer'] ?? null,
            'points' => $data['points'] ?? 1,
            'explanation' => $data['explanation'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }

    public function updateExam(\App\Models\Exam $exam, array $data): \App\Models\Exam
    {
        $old = $exam->file_path;
        $updated = $this->content->updateExam($exam, $data);
        if (array_key_exists('file_path', $data) && $data['file_path'] !== $old) {
            $this->deletePublicFile($old);
        }

        return $updated;
    }

    public function deleteExam(\App\Models\Exam $exam): void
    {
        $this->deletePublicFile($exam->file_path);
        $this->content->deleteExam($exam);
    }

    /* ---------------------------- Assignments ----------------------------- */

    public function createAssignment(int $courseId, array $data): \App\Models\Assignment
    {
        return $this->content->createAssignment([
            'course_id' => $courseId,
            'lesson_id' => $data['lesson_id'] ?? null,
            'title' => $data['title'],
            'instructions' => $data['instructions'] ?? null,
            'file_path' => $data['file_path'] ?? null,
            'submission_type' => $data['submission_type'] ?? \App\Models\Assignment::SUBMISSION_TEXT,
            'due_after_days' => $data['due_after_days'] ?? null,
            'opens_at' => $data['opens_at'] ?? null,
            'closes_at' => $data['closes_at'] ?? null,
            'max_score' => $data['max_score'] ?? 100,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_published' => $data['is_published'] ?? false,
        ]);
    }

    public function updateAssignment(\App\Models\Assignment $assignment, array $data): \App\Models\Assignment
    {
        $old = $assignment->file_path;
        $updated = $this->content->updateAssignment($assignment, $data);
        if (array_key_exists('file_path', $data) && $data['file_path'] !== $old) {
            $this->deletePublicFile($old);
        }

        return $updated;
    }

    public function deleteAssignment(\App\Models\Assignment $assignment): void
    {
        $this->deletePublicFile($assignment->file_path);
        $this->content->deleteAssignment($assignment);
    }

    /* ------------------------------ Grading ------------------------------- */

    /**
     * Instructor inbox rows: learner, assessment title, content/file, score.
     *
     * @return list<array<string, mixed>>
     */
    public function submissionsForCourse(int $courseId, ?string $status = null): array
    {
        return $this->submissions->forCourse($courseId, $status)
            ->loadMissing(['user', 'submissionable'])
            ->map(fn ($s) => [
                'id' => $s->id,
                'user_id' => $s->user_id,
                'learner_name' => $s->user?->name ?? 'Learner',
                'learner_email' => $s->user?->email ?? '',
                'assessment_type' => strtolower((string) class_basename($s->submissionable_type)),
                'assessment_id' => $s->submissionable_id,
                'assessment_title' => $s->submissionable?->title ?? 'Assessment',
                'content' => $s->content,
                'file_path' => $s->file_path,
                'status' => $s->status,
                'score' => $s->score,
                'grade' => $s->grade,
                'max_score' => $s->max_score,
                'feedback' => $s->feedback,
                'submitted_at' => $s->submitted_at?->toIso8601String(),
                'graded_at' => $s->graded_at?->toIso8601String(),
            ])->all();
    }

    protected function nextSort(string $class, int $courseId): int
    {
        $column = $class === Lesson::class ? 'course_id' : 'course_id';

        return (int) $class::query()->where($column, $courseId)->max('sort_order') + 1;
    }
}