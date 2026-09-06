<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Course;
use App\Repositories\Contracts\CourseRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;

class CourseRepository implements CourseRepositoryInterface
{
    public function published(?string $search = null): Collection
    {
        return $this->applySearch(
            Course::query()->where('status', Course::STATUS_PUBLISHED),
            $search,
        )->get();
    }

    public function all(?string $search = null): Collection
    {
        return $this->withEnrollmentStats($this->applySearch(Course::query(), $search))->get();
    }

    public function forCreator(int $userId, ?string $search = null): Collection
    {
        return $this->withEnrollmentStats($this->applySearch(
            Course::query()->where('created_by', $userId),
            $search,
        ))->get();
    }

    /**
     * Aggregate enrollment counters per course in a single query (no N+1) for
     * the management listing: who enrolled, who paid tuition, who is certified.
     */
    protected function withEnrollmentStats(Builder $query): Builder
    {
        $paidTuition = fn (Builder $q) => $q->whereHas('payments', function (Builder $p): void {
            $p->where('fee_type', 'tuition')->where('status', 'paid');
        });

        return $query->withCount([
            'enrollments as enrolled_count' => fn (Builder $q) => $q->whereNotIn('status', ['rejected', 'cancelled']),
            'enrollments as pending_review_count' => fn (Builder $q) => $q->whereIn('status', ['applied', 'application_fee_paid']),
            'enrollments as admitted_count' => fn (Builder $q) => $q->where('status', 'admitted'),
            'enrollments as tuition_paid_count' => $paidTuition,
            'enrollments as in_progress_count' => fn (Builder $q) => $q->whereIn('status', ['tuition_paid', 'in_progress']),
            'enrollments as completed_count' => fn (Builder $q) => $q->whereIn('status', ['completed', 'certification', 'certified']),
            'enrollments as certified_count' => fn (Builder $q) => $q->where('status', 'certified'),
            'enrollments as rejected_count' => fn (Builder $q) => $q->where('status', 'rejected'),
            'enrollments as cancelled_count' => fn (Builder $q) => $q->where('status', 'cancelled'),
            'certificates as certificates_issued_count',
        ]);
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

    protected function applySearch(Builder $query, ?string $search): Builder
    {
        $query->with('fees')->orderBy('created_at', 'desc');

        if ($search !== null && trim($search) !== '') {
            $term = '%'.addcslashes(trim($search), '%_\\').'%';
            $query->where(function (Builder $q) use ($term): void {
                $q->where('title', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('category', 'like', $term)
                    ->orWhere('slug', 'like', $term);
            });
        }

        return $query;
    }
}