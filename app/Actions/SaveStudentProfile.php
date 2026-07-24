<?php

namespace App\Actions;

use App\Models\Student;
use Illuminate\Support\Facades\DB;

final class SaveStudentProfile
{
    /**
     * @param  array{
     *     dni: string,
     *     first_name: string,
     *     last_name: string,
     *     birth_date: ?string,
     *     phone: ?string,
     *     address: ?string,
     *     observation: ?string,
     *     is_active: bool
     * }  $attributes
     */
    public function handle(?Student $student, array $attributes): Student
    {
        return DB::transaction(function () use ($student, $attributes): Student {
            $student ??= new Student;
            $student->fill($attributes);
            $student->save();
            $student->authAccount()->update(['login' => $student->dni]);

            return $student;
        });
    }
}
