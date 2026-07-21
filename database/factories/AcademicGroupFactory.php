<?php

namespace Database\Factories;

use App\Models\AcademicGroup;
use App\Models\CycleDegree;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicGroup>
 */
class AcademicGroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cycle_degree_code' => CycleDegree::factory(),
            'name' => fake()->unique()->lexify('?'),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
