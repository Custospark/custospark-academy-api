<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\AssessmentAttempt;
use App\Models\LessonProgress;
use App\Models\Submission;
use App\Repositories\Contracts\SubmissionRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class SubmissionRepository implements SubmissionRepositoryInterface
{
    public function forCourse(int $courseId, ?string $status = null): Collection
    {
        $query = Submission::query()->where('course_id', $courseId)->with(['user', 'submissionable']);

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    public function find(int $id): ?Submission
    {
        return Submission::query()->with(['user', 'submissionable', 'grader'])->find($id);
    }

    public function create(array $data): Submission
    {
        return Submission::query()->create($data);
    }

    public function update(Submission $submission, array $data): Submission
    {
        $submission->update($data);

        return $submission->fresh();
    }

    public function createAttempt(array $data): AssessmentAttempt
    {
        return AssessmentAttempt::query()->create($data);
    }

    public function updateAttempt(AssessmentAttempt $attempt, array $data): AssessmentAttempt
    {
        $attempt->update($data);

        return $attempt->fresh();
    }

    public function lastAttempt(int $userId, string $assessmentableType, int $assessmentableId): ?AssessmentAttempt
    {
        return AssessmentAttempt::query()
            ->where('user_id', $userId)
            ->where('assessmentable_type', $assessmentableType)
            ->where('assessmentable_id', $assessmentableId)
            ->latest('created_at')
            ->first();
    }

    public function findLessonProgress(int $userId, int $lessonId): ?LessonProgress
    {
        return LessonProgress::query()
            ->where('user_id', $userId)
            ->where('lesson_id', $lessonId)
            ->first();
    }

    public function lessonProgressForCourse(int $userId, int $courseId): Collection
    {
        return LessonProgress::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->get();
    }

    public function upsertLessonProgress(int $userId, int $courseId, int $lessonId, array $data): LessonProgress
    {
        return LessonProgress::query()->updateOrCreate(
            ['user_id' => $userId, 'lesson_id' => $lessonId],
            array_merge($data, ['course_id' => $courseId]),
        );
    }
}