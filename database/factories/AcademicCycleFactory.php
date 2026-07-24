<?php

namespace Database\Factories;

use App\Models\AcademicCycle;
use App\Models\Branch;
use App\Support\Academic\CycleModality;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicCycle>
 */
class AcademicCycleFactory extends Factory
{
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-6 months', '-1 day');

        return [
            'branch_code' => Branch::factory(),
            'name' => fake()->unique()->sentence(3),
            'modality' => fake()->randomElement(CycleModality::cases()),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => fake()->dateTimeBetween('+1 day', '+6 months')->format('Y-m-d'),
            'is_active' => true,
        ];
    }
}
