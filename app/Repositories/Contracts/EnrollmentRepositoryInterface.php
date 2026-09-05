<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Enrollment;
use Illuminate\Database\Eloquent\Collection;

interface EnrollmentRepositoryInterface
{
    public function find(int $id): ?Enrollment;

    /** @return Collection<int, Enrollment> */
    public function forUser(int $userId): Collection;

    public function forCourse(int $courseId): Collection;

    public function findByCourseAndUser(int $courseId, int $userId): ?Enrollment;

    /** @return Collection<int, Enrollment> */
    public function withStatus(string $status): Collection;

    public function create(array $data): Enrollment;

    public function update(Enrollment $enrollment, array $data): Enrollment;

    public function delete(Enrollment $enrollment): bool;
}