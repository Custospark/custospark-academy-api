<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Enrollment;
use App\Repositories\Contracts\EnrollmentRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
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

    public function queryForAdmin(?int $courseId = null, ?string $status = null, ?string $search = null, ?int $instructorId = null): Collection
    {
        $query = Enrollment::query()
            ->with(['course.fees', 'user', 'payments', 'certificate'])
            ->orderByDesc('created_at');

        if ($instructorId !== null) {
            $query->whereHas('course', function (Builder $q) use ($instructorId): void {
                $q->where('created_by', $instructorId);
            });
        }

        if ($courseId !== null && $courseId > 0) {
            $query->where('course_id', $courseId);
        }

        if ($status !== null && trim($status) !== '') {
            $query->where('status', $status);
        }

        if ($search !== null && trim($search) !== '') {
            $term = '%'.addcslashes(trim($search), '%_\\').'%';
            $query->where(function (Builder $q) use ($term): void {
                $q->whereHas('user', function (Builder $uq) use ($term): void {
                    $uq->where('name', 'like', $term)->orWhere('email', 'like', $term);
                })->orWhereHas('course', function (Builder $cq) use ($term): void {
                    $cq->where('title', 'like', $term);
                });
            });
        }

        return $query->get();
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