<?php

namespace App\Actions;

use App\Models\Student;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * The only write path for student identity and profile attributes.
 */
final class SaveStudent
{
    /**
     * @param  array{dni: string, first_name: string, last_name: string, birth_date: ?string, phone: ?string, address: ?string, observation: ?string}  $attributes
     */
    public function handle(?Student $student, array $attributes): Student
    {
        try {
            if ($student === null) {
                return Student::query()->create($attributes);
            }

            $student->update($attributes);

            return $student->refresh();
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505'
                && str_contains($exception->getMessage(), 'students_dni_unique')) {
                throw ValidationException::withMessages([
                    'dni' => 'Ya existe un estudiante con este DNI.',
                ]);
            }

            throw $exception;
        }
    }
}
