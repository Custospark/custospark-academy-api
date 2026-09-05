<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'user_id' => User::factory(),
            'fee_type' => 'application',
            'amount' => 50000,
            'currency' => 'UGX',
            'status' => Payment::STATUS_PENDING,
            'method' => Payment::METHOD_MOBILE_MONEY,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Payment::STATUS_PAID,
            'paid_at' => now(),
        ]);
    }
}