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
        $start = fake()->dateTimeBetween('2026-01-01', '2026-06-30');

        return [
            'branch_code' => Branch::factory(),
            'name' => fake()->unique()->sentence(3),
            'modality' => fake()->randomElement(CycleModality::cases()),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => fake()->dateTimeBetween($start, '2026-12-31')->format('Y-m-d'),
            'is_active' => true,
        ];
    }
}
