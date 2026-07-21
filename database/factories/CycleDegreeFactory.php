<?php

namespace Database\Factories;

use App\Models\AcademicCycle;
use App\Models\CycleDegree;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CycleDegree>
 */
class CycleDegreeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cycle_code' => AcademicCycle::factory(),
            'number' => fake()->numberBetween(1, 6),
        ];
    }
}
