<?php

namespace Database\Factories;

use App\Models\EmployeeRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->optional()->safeEmail(),
            'phone' => fake()->optional()->phoneNumber(),
            'employee_role_code' => EmployeeRole::factory(),
            'is_active' => true,
            'is_super_admin' => false,
        ];
    }
}
