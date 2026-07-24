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
    protected $model = StudentContact::class;

    public function definition(): array
    {
        return [
            'student_code' => Student::factory(),
            'name' => fake()->name(),
            'phone' => fake()->optional()->numerify('9########'),
            'note' => fake()->optional()->randomElement(['Madre', 'Padre', 'Apoderado']),
        ];
    }
}
