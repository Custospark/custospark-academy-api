<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\CourseFee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseFee>
 */
class CourseFeeFactory extends Factory
{
    protected $model = CourseFee::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'fee_type' => CourseFee::FEE_APPLICATION,
            'amount' => 50000,
            'currency' => 'UGX',
            'is_required' => true,
        ];
    }

    public function application(): static
    {
        return $this->state(fn (array $attributes) => ['fee_type' => CourseFee::FEE_APPLICATION]);
    }

    public function tuition(): static
    {
        return $this->state(fn (array $attributes) => ['fee_type' => CourseFee::FEE_TUITION, 'amount' => 800000]);
    }

    public function certificate(): static
    {
        return $this->state(fn (array $attributes) => ['fee_type' => CourseFee::FEE_CERTIFICATE, 'amount' => 50000]);
    }
}