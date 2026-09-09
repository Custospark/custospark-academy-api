<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AssessmentAttempt;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Submission;
use App\Models\User;
use App\Repositories\Contracts\CourseRepositoryInterface;
use App\Repositories\Contracts\SubmissionRepositoryInterface;
use Illuminate\Validation\ValidationException;

/**
 * Learner-side work: submissions, assessment attempts, lesson progress and
 * grading. Split from CourseContentService (file-size discipline); behavior
 * is unchanged.
 */
class LearnerWorkService
{
    public function __construct(
        protected SubmissionRepositoryInterface $submissions,
        protected CourseRepositoryInterface $courses,
        protected CourseCompletionService $completion,
    ) {}

    public function submitWork(User $user, int $courseId, string $type, int $typeId, array $data): Submission
    {
        $morph = $this->resolveSubmissionable($type, $typeId);
        $model = $morph['type']::query()->find($morph['id']);
        if ($model !== null) {
            $this->assertWindowOpen($model, strtolower(class_basename($model)));
        }

        $submission = $this->submissions->create([
            'user_id' => $user->id,
            'course_id' => $courseId,
            'submissionable_type' => $morph['type'],
            'submissionable_id' => $morph['id'],
            'content' => $data['content'] ?? null,
            'file_path' => $data['file_path'] ?? null,
            'status' => Submission::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        // Auto-grade quiz/exercise type assessments.
        $this->autoGradeIfPossible($submission, $data);

        return $submission->fresh();
    }

    /** Block submissions outside the instructor's open/close window. */
    protected function assertWindowOpen(object $model, string $label): void
    {
        $now = now();
        if (! empty($model->opens_at) && $now->lt($model->opens_at)) {
            throw ValidationException::withMessages([
                'assessment' => "This {$label} opens on {$model->opens_at->format('j M Y, H:i')}.",
            ]);
        }
        if (! empty($model->closes_at) && $now->gt($model->closes_at)) {
            throw ValidationException::withMessages([
                'assessment' => "This {$label} closed on {$model->closes_at->format('j M Y, H:i')}.",
            ]);
        }
    }

    public function gradeSubmission(int $submissionId, int $graderId, array $data): Submission
    {
        $submission = $this->submissions->find($submissionId);
        if ($submission === null) {
            throw ValidationException::withMessages(['submission' => 'Submission not found.']);
        }

        return $this->submissions->update($submission, [
            'status' => Submission::STATUS_GRADED,
            'score' => $data['score'],
            'feedback' => $data['feedback'] ?? null,
            'graded_by' => $graderId,
            'graded_at' => now(),
        ]);
    }

    public function submitAssessmentAttempt(User $user, int $courseId, string $type, int $typeId, array $answers): AssessmentAttempt
    {
        $morph = $this->resolveAssessmentable($type, $typeId);
        $model = $morph['model'];
        $this->assertWindowOpen($model, strtolower(class_basename($model)));

        // Attempt budget: default 2, settable per quiz/exercise at creation.
        $max = (int) ($model->max_attempts ?? 2);
        $used = $this->submissions->attemptsFor((int) $user->id, (int) $courseId, $morph['type'], $morph['id']);
        if ($used >= $max) {
            throw ValidationException::withMessages([
                'attempts' => "You have used all {$max} attempts for this ".strtolower(class_basename($model)).'.',
            ]);
        }

        $questions = $model->questions;

        $score = 0;
        $max = 0;
        foreach ($questions as $question) {
            $max += $question->points;
            $given = $answers[$question->id] ?? null;
            if ($this->answerIsCorrect($question, $given)) {
                $score += $question->points;
            }
        }

        $attempt = $this->submissions->createAttempt([
            'user_id' => $user->id,
            'course_id' => $courseId,
            'assessmentable_type' => $morph['type'],
            'assessmentable_id' => $morph['id'],
            'answers' => $answers,
            'score' => $score,
            'max_score' => $max,
            'is_passed' => $max > 0 && $score >= ($max * ($model->passing_score / 100)),
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
        ]);

        return $attempt->fresh();
    }

    public function markLessonProgress(User $user, int $courseId, Lesson $lesson, string $status): \App\Models\LessonProgress
    {
        return $this->submissions->upsertLessonProgress($user->id, $courseId, (int) $lesson->id, [
            'status' => $status,
            'started_at' => $status === \App\Models\LessonProgress::STATUS_IN_PROGRESS ? now() : null,
            'completed_at' => $status === \App\Models\LessonProgress::STATUS_COMPLETED ? now() : null,
        ]);
    }

    /** Progress summary + the completion manifest (every required item type). */
    public function courseProgress(User $user, int $courseId): array
    {
        $course = $this->courses->find($courseId);

        if ($course === null) {
            return [
                'total_lessons' => 0,
                'completed_lessons' => 0,
                'percent' => 0,
                'completion' => [
                    'total_required' => 0,
                    'completed_required' => 0,
                    'percent' => 100,
                    'is_complete' => true,
                    'delivery_mode' => 'self_paced',
                    'auto_completes' => true,
                    'categories' => [],
                    'pending_instructor' => [],
                ],
            ];
        }

        $total = $course->lessons()->count();
        $completed = $this->submissions->lessonProgressForCourse($user->id, $courseId)
            ->filter(fn ($p) => $p->isCompleted())
            ->count();

        return [
            'total_lessons' => $total,
            'completed_lessons' => $completed,
            'percent' => $total > 0 ? (int) round(($completed / $total) * 100) : 0,
            'completion' => $this->completion->evaluate($user, $course),
        ];
    }

    /* ------------------------------ Helpers ------------------------------- */

    protected function resolveSubmissionable(string $type, int $id): array
    {
        return match ($type) {
            'assignment' => ['type' => \App\Models\Assignment::class, 'id' => $id],
            'exercise' => ['type' => \App\Models\Exercise::class, 'id' => $id],
            'exam' => ['type' => \App\Models\Exam::class, 'id' => $id],
            default => throw ValidationException::withMessages(['type' => 'Invalid submission type.']),
        };
    }

    protected function resolveAssessmentable(string $type, int $id): array
    {
        $model = match ($type) {
            'quiz' => \App\Models\Quiz::query()->with('questions')->findOrFail($id),
            'exercise' => \App\Models\Exercise::query()->with('questions')->findOrFail($id),
            default => throw ValidationException::withMessages(['type' => 'Invalid assessment type.']),
        };

        return ['type' => $model::class, 'id' => $id, 'model' => $model];
    }

    protected function autoGradeIfPossible(Submission $submission, array $data): void
    {
        $assessmentable = $submission->submissionable;
        if ($assessmentable instanceof \App\Models\Exercise && $assessmentable->type === \App\Models\Exercise::TYPE_QUIZ) {
            $answers = $data['answers'] ?? [];
            $score = 0;
            $max = 0;
            foreach ($assessmentable->questions as $question) {
                $max += $question->points;
                if ($this->answerIsCorrect($question, $answers[$question->id] ?? null)) {
                    $score += $question->points;
                }
            }
            $this->submissions->update($submission, [
                'status' => Submission::STATUS_GRADED,
                'score' => $score,
                'max_score' => $max,
                'graded_at' => now(),
            ]);
        }
    }

    protected function answerIsCorrect($question, $given): bool
    {
        if ($given === null || $given === '') {
            return false;
        }

        return strtolower((string) $given) === strtolower((string) $question->correct_answer);
    }

}
