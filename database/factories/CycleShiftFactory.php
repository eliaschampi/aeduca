<?php

namespace Database\Factories;

use App\Models\AcademicCycle;
use App\Models\CycleShift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CycleShift>
 */
class CycleShiftFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cycle_code' => AcademicCycle::factory(),
            'name' => fake()->unique()->word(),
            'entry_time' => '07:00',
            'tolerance_minutes' => fake()->numberBetween(0, 60),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
