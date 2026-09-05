<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\AssessmentAttempt;
use App\Models\LessonProgress;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Collection;

interface SubmissionRepositoryInterface
{
    /** @return Collection<int, Submission> */
    public function forCourse(int $courseId, ?string $status = null): Collection;

    public function find(int $id): ?Submission;

    public function create(array $data): Submission;

    public function update(Submission $submission, array $data): Submission;

    public function createAttempt(array $data): AssessmentAttempt;

    public function updateAttempt(AssessmentAttempt $attempt, array $data): AssessmentAttempt;

    public function lastAttempt(int $userId, string $assessmentableType, int $assessmentableId): ?AssessmentAttempt;

    public function findLessonProgress(int $userId, int $lessonId): ?LessonProgress;

    /** @return Collection<int, LessonProgress> */
    public function lessonProgressForCourse(int $userId, int $courseId): Collection;

    public function upsertLessonProgress(int $userId, int $courseId, int $lessonId, array $data): LessonProgress;
}