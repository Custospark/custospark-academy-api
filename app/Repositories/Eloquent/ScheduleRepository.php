<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Enrollment;
use App\Models\Schedule;
use App\Repositories\Contracts\ScheduleRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ScheduleRepository implements ScheduleRepositoryInterface
{
    public function forCourse(int $courseId): Collection
    {
        return Schedule::query()
            ->where('course_id', $courseId)
            ->with('instructor')
            ->orderBy('starts_at')
            ->get();
    }

    public function forCourseBetween(int $courseId, string $from, string $to): Collection
    {
        return Schedule::query()
            ->where('course_id', $courseId)
            ->whereBetween('starts_at', [$from, $to])
            ->orderBy('starts_at')
            ->get();
    }

    public function find(int $id): ?Schedule
    {
        return Schedule::query()->with(['course', 'instructor'])->find($id);
    }

    public function forLearner(int $userId): Collection
    {
        return Schedule::query()
            ->with(['course', 'instructor'])
            ->whereHas('course.enrollments', function (Builder $q) use ($userId): void {
                $q->where('user_id', $userId)
                    ->whereNotIn('status', [Enrollment::STATUS_REJECTED, Enrollment::STATUS_CANCELLED]);
            })
            ->orderBy('starts_at')
            ->get();
    }

    public function create(array $data): Schedule
    {
        return Schedule::query()->create($data);
    }

    public function update(Schedule $schedule, array $data): Schedule
    {
        $schedule->update($data);

        return $schedule->fresh();
    }

    public function delete(Schedule $schedule): bool
    {
        return (bool) $schedule->delete();
    }
}