<?php

namespace App\Actions;

use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class DeleteStudent
{
    public function handle(Student $student): void
    {
        $photoPath = DB::transaction(function () use ($student): ?string {
            $student = Student::query()->lockForUpdate()->findOrFail($student->code);

            if ($student->enrollments()->exists()) {
                throw ValidationException::withMessages([
                    'student' => 'No se puede eliminar un alumno con matrículas registradas.',
                ]);
            }

            if ($student->attentions()->exists()) {
                throw ValidationException::withMessages([
                    'student' => 'No se puede eliminar un alumno con atenciones registradas.',
                ]);
            }

            $photoPath = $student->photo_path;
            $student->delete();

            return $photoPath;
        });

        if ($photoPath) {
            Storage::disk('local')->delete($photoPath);
        }
    }
}
