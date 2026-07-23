<?php

namespace App\Actions;

use App\Models\Student;
use App\Models\StudentContact;
use Illuminate\Support\Facades\DB;

/**
 * Append one contact while serializing position assignment per student.
 */
final class CreateStudentContact
{
    /**
     * @param  array{name: string, phone: ?string, note: ?string}  $attributes
     */
    public function handle(Student $student, array $attributes): StudentContact
    {
        return DB::transaction(function () use ($student, $attributes): StudentContact {
            $student = Student::query()
                ->lockForUpdate()
                ->findOrFail($student->code);
            $position = (int) StudentContact::query()
                ->where('student_code', $student->code)
                ->max('position') + 1;

            return $student->contacts()->create([
                ...$attributes,
                'position' => $position,
            ]);
        });
    }
}
