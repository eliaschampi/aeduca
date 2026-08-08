<?php

namespace App\Actions;

use App\Models\Branch;
use App\Models\DriveFile;
use App\Models\Student;
use App\Models\StudentAttention;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveStudentAttention
{
    /**
     * @param  array{student_code: string, type: string, reason: string, development: string, conclusion: string, occurred_at: string, drive_file_code: ?string}  $attributes
     */
    public function handle(
        ?StudentAttention $attention,
        Branch $branch,
        User $actor,
        array $attributes,
    ): StudentAttention {
        return DB::transaction(function () use ($attention, $branch, $actor, $attributes): StudentAttention {
            $student = Student::query()->lockForUpdate()->findOrFail($attributes['student_code']);

            if ($attention) {
                $attention = StudentAttention::query()->lockForUpdate()->findOrFail($attention->code);
                abort_unless(
                    $attention->branch_code === $branch->code
                        && $attention->student_code === $student->code,
                    404,
                );
            } else {
                $this->assertStudentBelongsToBranch($student, $branch);
                $attention = new StudentAttention;
                $attention->student_code = $student->code;
                $attention->branch_code = $branch->code;
                $attention->created_by_user_code = $actor->code;
            }

            if ($attention->drive_file_code !== $attributes['drive_file_code']) {
                $this->assertAttachableFile($attributes['drive_file_code'], $actor);
                $attention->drive_file_code = $attributes['drive_file_code'];
            }

            $occurredAt = CarbonImmutable::createFromFormat(
                '!Y-m-d\TH:i',
                $attributes['occurred_at'],
                (string) config('aeduca.business_timezone', 'America/Lima'),
            );
            abort_unless($occurredAt, 422);

            $attention->fill([
                'type' => $attributes['type'],
                'reason' => $attributes['reason'],
                'development' => $attributes['development'],
                'conclusion' => $attributes['conclusion'],
                'occurred_at' => $occurredAt->utc(),
            ]);

            if ($attention->exists) {
                $attention->updated_by_user_code = $actor->code;
            }

            $attention->save();

            return $attention;
        });
    }

    private function assertStudentBelongsToBranch(Student $student, Branch $branch): void
    {
        $belongsToBranch = DB::table('enrollments as e')
            ->join('academic_cycles as c', 'c.code', '=', 'e.cycle_code')
            ->where('e.student_code', $student->code)
            ->where('c.branch_code', $branch->code)
            ->exists();

        if (! $belongsToBranch) {
            throw ValidationException::withMessages([
                'student_code' => 'El alumno no tiene una matrícula registrada en la sede actual.',
            ]);
        }
    }

    private function assertAttachableFile(?string $fileCode, User $actor): void
    {
        if (! $fileCode) {
            return;
        }

        $file = DriveFile::query()->lockForUpdate()->find($fileCode);

        if (
            ! $file
            || $file->user_code !== $actor->code
            || $file->isDirectory()
            || $file->isTrashed()
        ) {
            throw ValidationException::withMessages([
                'drive_file_code' => 'Selecciona un archivo disponible de tu propio Drive.',
            ]);
        }
    }
}
