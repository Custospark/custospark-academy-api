<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AssessmentAttempt;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\Exam;
use App\Models\Exercise;
use App\Models\LessonProgress;
use App\Models\Quiz;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Determines whether a learner has completed a course by evaluating every
 * required learning item: lessons, quizzes, exercises, exams and assignments.
 *
 * Item accounting follows who controls the "done" decision:
 *   - learner   lessons (marked complete by the learner)
 *   - auto      quizzes + quiz-type exercises (system-graded, pass attempt)
 *   - instructor practical exercises, exams and assignments (only count once
 *               a submission has been graded by the instructor)
 */
class CourseCompletionService
{
    public function __construct(
        protected \App\Repositories\Contracts\SubmissionRepositoryInterface $submissions,
    ) {}

    /**
     * Build the completion manifest for a learner in a course.
     *
     * @return array{
     *     total_required: int,
     *     completed_required: int,
     *     percent: int,
     *     is_complete: bool,
     *     delivery_mode: string,
     *     auto_completes: bool,
     *     categories: list<array<string, mixed>>,
     *     pending_instructor: list<array<string, mixed>>,
     * }
     */
    public function evaluate(User $user, Course $course): array
    {
        $lessonProgress = $this->lessonProgress($user, $course);
        $passedAttempts = $this->passedAttempts($user, $course);
        $submissions = $this->submissions($user, $course);

        $categories = [
            $this->lessons($course, $lessonProgress),
            $this->quizzes($course, $passedAttempts),
            $this->exercises($course, $passedAttempts, $submissions),
            $this->exams($course, $submissions),
            $this->assignments($course, $submissions),
        ];

        $total = (int) array_sum(array_column($categories, 'total'));
        $completed = (int) array_sum(array_column($categories, 'completed'));
        $isComplete = $total === 0 || $completed >= $total;

        return [
            'total_required' => $total,
            'completed_required' => $completed,
            'percent' => $total > 0 ? (int) round(($completed / $total) * 100) : 100,
            'is_complete' => $isComplete,
            'delivery_mode' => $course->delivery_mode,
            'auto_completes' => $course->isSelfPaced() || $course->isHybrid(),
            'categories' => array_values($categories),
            'pending_instructor' => $this->pendingInstructorItems($course, $submissions),
        ];
    }

    /* ---------------------------- Categories ---------------------------- */

    /** @param  Collection<int, LessonProgress>  $progress */
    protected function lessons(Course $course, Collection $progress): array
    {
        $lessons = $course->lessons()->where('is_published', true)->get();
        $completed = $lessons->filter(fn ($lesson) => $progress->get((int) $lesson->id)?->isCompleted() ?? false)->count();

        return $this->category('lessons', 'Lessons', 'learner', $lessons->count(), $completed);
    }

    /** @param  array<string, true>  $passedByKey */
    protected function quizzes(Course $course, array $passedByKey): array
    {
        $quizzes = $course->quizzes()->where('is_published', true)->get();
        $completed = $quizzes->filter(fn ($quiz) => isset($passedByKey[$this->itemKey(Quiz::class, (int) $quiz->id)]))->count();

        return $this->category('quizzes', 'Quizzes', 'auto', $quizzes->count(), $completed);
    }

    /**
     * @param  array<string, true>  $passedByKey
     * @param  Collection<int, Submission>  $submissions
     */
    protected function exercises(Course $course, array $passedByKey, Collection $submissions): array
    {
        $items = $course->exercises()->where('is_published', true)->get();
        $completed = 0;
        $hasInstructorItems = false;

        foreach ($items as $exercise) {
            if ($exercise->type === Exercise::TYPE_QUIZ) {
                if (isset($passedByKey[$this->itemKey(Exercise::class, (int) $exercise->id)])) {
                    $completed++;
                }

                continue;
            }

            $hasInstructorItems = true;
            $submission = $this->latestSubmission($submissions, Exercise::class, (int) $exercise->id);
            if ($submission !== null && $this->isPassingSubmission($submission, $exercise)) {
                $completed++;
            }
        }

        return $this->category(
            'exercises',
            'Exercises',
            $hasInstructorItems ? 'instructor' : 'auto',
            $items->count(),
            $completed,
        );
    }

    /** @param  Collection<int, Submission>  $submissions */
    protected function exams(Course $course, Collection $submissions): array
    {
        $items = $course->exams()->where('is_published', true)->get();
        $completed = $items
            ->filter(fn ($exam) => $this->isPassingSubmission(
                $this->latestSubmission($submissions, Exam::class, (int) $exam->id),
                $exam,
            ))
            ->count();

        return $this->category('exams', 'Exams', 'instructor', $items->count(), $completed);
    }

    /** @param  Collection<int, Submission>  $submissions */
    protected function assignments(Course $course, Collection $submissions): array
    {
        $items = $course->assignments()->where('is_published', true)->get();
        $completed = $items
            ->filter(fn ($assignment) => $this->latestSubmission($submissions, Assignment::class, (int) $assignment->id)?->isGraded() ?? false)
            ->count();

        return $this->category('assignments', 'Assignments', 'instructor', $items->count(), $completed);
    }

    /* ----------------------------- Pending ------------------------------ */

    /** @param  Collection<int, Submission>  $submissions */
    protected function pendingInstructorItems(Course $course, Collection $submissions): array
    {
        $pending = [];

        foreach ($course->exams()->where('is_published', true)->get() as $exam) {
            $submission = $this->latestSubmission($submissions, Exam::class, (int) $exam->id);
            if ($submission !== null && ! $submission->isGraded()) {
                $pending[] = $this->pendingItem('exam', (int) $exam->id, $exam->title, $submission->status);
            }
        }

        foreach ($course->assignments()->where('is_published', true)->get() as $assignment) {
            $submission = $this->latestSubmission($submissions, Assignment::class, (int) $assignment->id);
            if ($submission !== null && ! $submission->isGraded()) {
                $pending[] = $this->pendingItem('assignment', (int) $assignment->id, $assignment->title, $submission->status);
            }
        }

        foreach ($course->exercises()->where('is_published', true)->where('type', Exercise::TYPE_PRACTICAL)->get() as $exercise) {
            $submission = $this->latestSubmission($submissions, Exercise::class, (int) $exercise->id);
            if ($submission !== null && ! $submission->isGraded()) {
                $pending[] = $this->pendingItem('exercise', (int) $exercise->id, $exercise->title, $submission->status);
            }
        }

        return $pending;
    }

    /* ------------------------------ Helpers ------------------------------ */

    protected function category(string $key, string $label, string $grading, int $total, int $completed): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'grading' => $grading,
            'total' => $total,
            'completed' => $completed,
            'percent' => $total > 0 ? (int) round(($completed / $total) * 100) : 100,
        ];
    }

    protected function pendingItem(string $type, int $id, ?string $title, string $status): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'title' => $title,
            'status' => $status,
        ];
    }

    protected function itemKey(string $type, int $id): string
    {
        return $type.'|'.$id;
    }

    protected function isPassingSubmission(?Submission $submission, $assessment): bool
    {
        if ($submission === null || ! $submission->isGraded()) {
            return false;
        }

        $threshold = (int) ceil(((float) $assessment->max_score * (float) $assessment->passing_score) / 100);

        return (float) $submission->score >= $threshold;
    }

    /** @param  Collection<int, Submission>  $submissions */
    protected function latestSubmission(Collection $submissions, string $type, int $id): ?Submission
    {
        return $submissions->first(
            fn (Submission $submission) => $submission->submissionable_type === $type
                && (int) $submission->submissionable_id === $id,
        );
    }

    /** @return Collection<int, LessonProgress> */
    protected function lessonProgress(User $user, Course $course): Collection
    {
        return LessonProgress::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->get()
            ->keyBy('lesson_id');
    }

    /** @return array<string, true> */
    protected function passedAttempts(User $user, Course $course): array
    {
        $passed = [];

        AssessmentAttempt::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('is_passed', true)
            ->get()
            ->each(function (AssessmentAttempt $attempt) use (&$passed): void {
                $passed[$this->itemKey($attempt->assessmentable_type, (int) $attempt->assessmentable_id)] = true;
            });

        return $passed;
    }

    /** @return Collection<int, Submission> */
    protected function submissions(User $user, Course $course): Collection
    {
        return Submission::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->orderByDesc('submitted_at')
            ->get()
            ->unique(fn (Submission $submission) => $this->itemKey($submission->submissionable_type, (int) $submission->submissionable_id));
    }
}