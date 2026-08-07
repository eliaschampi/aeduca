<?php

namespace App\Actions;

use App\Models\Branch;
use App\Models\Student;
use App\Models\StudentAttention;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveStudentAttention
{
    /**
     * @param  array{type: string, reason: string, development: string, conclusion: string, occurred_at: string}  $attributes
     */
    public function handle(
        ?StudentAttention $attention,
        Student $student,
        Branch $branch,
        User $actor,
        array $attributes,
    ): StudentAttention {
        return DB::transaction(function () use (
            $attention,
            $student,
            $branch,
            $actor,
            $attributes,
        ): StudentAttention {
            $student = Student::query()->lockForUpdate()->findOrFail($student->code);

            if ($attention) {
                $attention = StudentAttention::query()->lockForUpdate()->findOrFail($attention->code);
                abort_unless(
                    $attention->student_code === $student->code
                        && $attention->branch_code === $branch->code,
                    404,
                );
            } else {
                $this->assertStudentBelongsToBranch($student, $branch);
                $attention = new StudentAttention;
                $attention->student_code = $student->code;
                $attention->branch_code = $branch->code;
                $attention->created_by_user_code = $actor->code;
            }

            $attention->fill([
                ...$attributes,
                'occurred_at' => CarbonImmutable::createFromFormat(
                    '!Y-m-d\TH:i',
                    $attributes['occurred_at'],
                    (string) config('aeduca.business_timezone', 'America/Lima'),
                )?->utc(),
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
                'student' => 'El alumno no tiene una matrícula registrada en la sede actual.',
            ]);
        }
    }
}
