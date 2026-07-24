<?php

namespace Database\Factories;

use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    protected $model = Student::class;

    public function definition(): array
    {
        return [
            'dni' => fake()->unique()->numerify('########'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'birth_date' => fake()->dateTimeBetween('-18 years', '-5 years')->format('Y-m-d'),
            'phone' => fake()->optional()->numerify('9########'),
            'address' => fake()->optional()->address(),
            'observation' => null,
            'photo_path' => null,
            'is_active' => true,
        ];
    }
}
