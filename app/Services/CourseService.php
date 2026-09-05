<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Repositories\Contracts\CourseFeeRepositoryInterface;
use App\Repositories\Contracts\CourseRepositoryInterface;
use App\Repositories\Contracts\ScheduleRepositoryInterface;

class CourseService
{
    public function __construct(
        protected CourseRepositoryInterface $courses,
        protected CourseFeeRepositoryInterface $fees,
        protected ScheduleRepositoryInterface $schedules,
    ) {}

    public function publishedCourses(?string $search = null): array
    {
        return $this->courses->published($search)->all();
    }

    public function allCourses(?string $search = null): array
    {
        return $this->courses->all($search)->all();
    }

    public function coursesForCreator(int $userId, ?string $search = null): array
    {
        return $this->courses->forCreator($userId, $search)->all();
    }

    public function findCourse(int $id): ?Course
    {
        return $this->courses->find($id);
    }

    public function createCourse(array $data, int $creatorId): Course
    {
        return $this->courses->create([
            ...$data,
            'created_by' => $creatorId,
            'status' => $data['status'] ?? Course::STATUS_DRAFT,
        ]);
    }

    public function updateCourse(Course $course, array $data): Course
    {
        return $this->courses->update($course, $data);
    }

    public function deleteCourse(Course $course): void
    {
        $this->courses->delete($course);
    }

    public function setFee(Course $course, string $feeType, float $amount, string $currency = 'UGX', bool $required = true): \App\Models\CourseFee
    {
        $existing = $this->fees->forCourse((int) $course->id, $feeType);

        if ($existing !== null) {
            return $this->fees->update($existing, [
                'amount' => $amount,
                'currency' => $currency,
                'is_required' => $required,
            ]);
        }

        return $this->fees->create([
            'course_id' => $course->id,
            'fee_type' => $feeType,
            'amount' => $amount,
            'currency' => $currency,
            'is_required' => $required,
        ]);
    }

    public function schedulesForCourse(int $courseId): array
    {
        return $this->schedules->forCourse($courseId)->all();
    }

    public function createSchedule(int $courseId, array $data): \App\Models\Schedule
    {
        return $this->schedules->create([
            'course_id' => $courseId,
            ...$data,
        ]);
    }
}