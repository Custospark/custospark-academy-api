<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\CourseFee;
use App\Repositories\Contracts\CourseFeeRepositoryInterface;

class CourseFeeRepository implements CourseFeeRepositoryInterface
{
    public function forCourse(int $courseId, string $feeType): ?CourseFee
    {
        return CourseFee::query()
            ->where('course_id', $courseId)
            ->where('fee_type', $feeType)
            ->first();
    }

    public function create(array $data): CourseFee
    {
        return CourseFee::query()->create($data);
    }

    public function update(CourseFee $fee, array $data): CourseFee
    {
        $fee->update($data);

        return $fee->fresh();
    }

    public function delete(CourseFee $fee): bool
    {
        return (bool) $fee->delete();
    }
}