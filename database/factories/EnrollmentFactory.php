<?php

namespace Database\Factories;

use App\Models\AcademicGroup;
use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enrollment>
 */
class EnrollmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_code' => Student::factory(),
            'academic_group_code' => AcademicGroup::factory(),
            'roll_code' => fake()->unique()->numerify('####'),
            'is_active' => true,
            'observation' => null,
        ];
    }
}
