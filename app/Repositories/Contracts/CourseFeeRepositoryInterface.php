<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\CourseFee;

interface CourseFeeRepositoryInterface
{
    public function forCourse(int $courseId, string $feeType): ?CourseFee;

    public function create(array $data): CourseFee;

    public function update(CourseFee $fee, array $data): CourseFee;

    public function delete(CourseFee $fee): bool;
}