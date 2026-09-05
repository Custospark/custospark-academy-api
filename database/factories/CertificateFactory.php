<?php

namespace Database\Factories;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Certificate>
 */
class CertificateFactory extends Factory
{
    protected $model = Certificate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'user_id' => User::factory(),
            'course_id' => Course::factory(),
            'certificate_reference' => 'CSA-'.fake()->unique()->numberBetween(1000, 9999),
            'issued_at' => now(),
        ];
    }
}