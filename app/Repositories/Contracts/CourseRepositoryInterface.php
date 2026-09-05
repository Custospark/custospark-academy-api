<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Course;
use Illuminate\Database\Eloquent\Collection;

interface CourseRepositoryInterface
{
    /** @return Collection<int, Course> */
    public function published(?string $search = null): Collection;

    /** @return Collection<int, Course> */
    public function all(?string $search = null): Collection;

    /** @return Collection<int, Course> */
    public function forCreator(int $userId, ?string $search = null): Collection;

    public function findBySlug(string $slug): ?Course;

    public function find(int $id): ?Course;

    public function create(array $data): Course;

    public function update(Course $course, array $data): Course;

    public function delete(Course $course): bool;
}