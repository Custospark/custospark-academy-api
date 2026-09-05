<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Course;
use App\Repositories\Contracts\CourseRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class CourseRepository implements CourseRepositoryInterface
{
    public function published(): Collection
    {
        return Course::query()
            ->where('status', Course::STATUS_PUBLISHED)
            ->with('fees')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function all(): Collection
    {
        return Course::query()->with('fees')->orderBy('created_at', 'desc')->get();
    }

    public function findBySlug(string $slug): ?Course
    {
        return Course::query()->with('fees')->where('slug', $slug)->first();
    }

    public function find(int $id): ?Course
    {
        return Course::query()->with('fees')->find($id);
    }

    public function create(array $data): Course
    {
        return Course::query()->create($data);
    }

    public function update(Course $course, array $data): Course
    {
        $course->update($data);

        return $course->fresh();
    }

    public function delete(Course $course): bool
    {
        return (bool) $course->delete();
    }
}