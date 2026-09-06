<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Schedule;
use Illuminate\Database\Eloquent\Collection;

interface ScheduleRepositoryInterface
{
    /** @return Collection<int, Schedule> */
    public function forCourse(int $courseId): Collection;

    /** @return Collection<int, Schedule> */
    public function forCourseBetween(int $courseId, string $from, string $to): Collection;

    public function find(int $id): ?Schedule;

    /**
     * Schedules across every course the learner has an active (non rejected /
     * cancelled) enrollment in - powers the learner "Schedules" page.
     *
     * @return Collection<int, Schedule>
     */
    public function forLearner(int $userId): Collection;

    public function create(array $data): Schedule;

    public function update(Schedule $schedule, array $data): Schedule;

    public function delete(Schedule $schedule): bool;
}