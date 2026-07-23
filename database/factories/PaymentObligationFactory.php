<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\PaymentObligation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentObligation>
 */
class PaymentObligationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'enrollment_code' => Enrollment::factory(),
            'concept' => fake()->randomElement(['Matrícula', 'Pensión']),
            'amount' => fake()->randomFloat(2, 1, 1000),
            'due_date' => fake()->date(),
        ];
    }
}
