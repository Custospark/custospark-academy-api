<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Course;
use Illuminate\Database\Eloquent\Collection;

interface CourseRepositoryInterface
{
    /** @return Collection<int, Course> */
    public function published(): Collection;

    /** @return Collection<int, Course> */
    public function all(): Collection;

    /** @return Collection<int, Course> */
    public function forCreator(int $userId): Collection;

    public function findBySlug(string $slug): ?Course;

    public function find(int $id): ?Course;

    public function create(array $data): Course;

    public function update(Course $course, array $data): Course;

    public function delete(Course $course): bool;
}