<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Enrollment;
use App\Repositories\Contracts\EnrollmentRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class EnrollmentRepository implements EnrollmentRepositoryInterface
{
    public function find(int $id): ?Enrollment
    {
        return Enrollment::query()->with(['course.fees', 'user'])->find($id);
    }

    public function forUser(int $userId): Collection
    {
        return Enrollment::query()
            ->where('user_id', $userId)
            ->with(['course.fees', 'certificate'])
            ->orderByDesc('created_at')
            ->get();
    }

    public function forCourse(int $courseId): Collection
    {
        return Enrollment::query()
            ->where('course_id', $courseId)
            ->with(['user', 'payments'])
            ->orderByDesc('created_at')
            ->get();
    }

    public function findByCourseAndUser(int $courseId, int $userId): ?Enrollment
    {
        return Enrollment::query()
            ->with(['course.fees', 'payments', 'certificate'])
            ->where('course_id', $courseId)
            ->where('user_id', $userId)
            ->first();
    }

    public function withStatus(string $status): Collection
    {
        return Enrollment::query()
            ->where('status', $status)
            ->with(['course', 'user'])
            ->get();
    }

    public function create(array $data): Enrollment
    {
        return Enrollment::query()->create($data);
    }

    public function update(Enrollment $enrollment, array $data): Enrollment
    {
        $enrollment->update($data);

        return $enrollment->fresh();
    }

    public function delete(Enrollment $enrollment): bool
    {
        return (bool) $enrollment->delete();
    }
}