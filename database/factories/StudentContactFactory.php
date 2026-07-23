<?php

namespace Database\Factories;

use App\Models\Student;
use App\Models\StudentContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentContact>
 */
class StudentContactFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_code' => Student::factory(),
            'position' => 1,
            'name' => fake()->name(),
            'phone' => fake()->optional()->phoneNumber(),
            'note' => fake()->optional()->sentence(),
        ];
    }
}
