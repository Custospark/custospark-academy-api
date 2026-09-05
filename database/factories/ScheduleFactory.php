<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Schedule>
 */
class ScheduleFactory extends Factory
{
    protected $model = Schedule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'instructor_id' => User::factory(),
            'title' => fake()->sentence(2),
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'is_online' => true,
        ];
    }
}