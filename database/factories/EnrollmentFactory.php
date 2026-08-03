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
    protected $model = Enrollment::class;

    public function definition(): array
    {
        return [
            'student_code' => Student::factory(),
            'academic_group_code' => AcademicGroup::factory(),
            'cycle_code' => fn (array $attributes): string => AcademicGroup::query()
                ->with('cycleDegree:code,cycle_code')
                ->findOrFail($attributes['academic_group_code'])
                ->cycleDegree
                ->cycle_code,
            'roll_code' => fake()->unique()->numerify('####'),
            'attendance_starts_on' => function (array $attributes): string {
                $group = AcademicGroup::query()
                    ->with('cycleDegree.cycle:code,start_date')
                    ->findOrFail($attributes['academic_group_code']);

                return $group->cycleDegree->cycle->start_date->toDateString();
            },
            'is_active' => true,
            'observation' => null,
        ];
    }
}
