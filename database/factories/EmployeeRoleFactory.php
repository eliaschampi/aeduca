<?php

namespace Database\Factories;

use App\Models\EmployeeRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeRole>
 */
class EmployeeRoleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->jobTitle(),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }
}
