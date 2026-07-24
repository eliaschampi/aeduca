<?php

namespace App\Actions;

use App\Models\Student;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class UpdateStudentPhoto
{
    public function handle(Student $student, UploadedFile $photo): Student
    {
        $newPath = $this->store($photo);
        $oldPath = $student->photo_path;

        try {
            $student->forceFill(['photo_path' => $newPath])->save();
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($newPath);

            throw $exception;
        }

        if ($oldPath && $oldPath !== $newPath) {
            Storage::disk('local')->delete($oldPath);
        }

        return $student;
    }

    private function store(UploadedFile $photo): string
    {
        $extension = $photo->guessExtension() ?: 'webp';
        $path = $photo->storeAs(
            'student-photos',
            Str::uuid().'.'.$extension,
            'local',
        );

        if (! is_string($path)) {
            throw new \RuntimeException('No se pudo almacenar la foto del alumno.');
        }

        return $path;
    }
}
