<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    protected $model = Course::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'slug' => Str::slug(fake()->unique()->sentence(3)),
            'description' => fake()->paragraph(),
            'category' => fake()->word(),
            'status' => Course::STATUS_PUBLISHED,
            'is_self_paced' => false,
            'created_by' => User::factory(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => ['status' => Course::STATUS_DRAFT]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => ['status' => Course::STATUS_PUBLISHED]);
    }
}