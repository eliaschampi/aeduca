<?php

namespace App\Actions;

use App\Models\AcademicGroup;
use App\Models\CycleShift;
use App\Models\Enrollment;
use App\Models\Student;
use App\Support\Business\BusinessCalendar;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only enrollment write path: academic assignment, selected shifts,
 * active-enrollment replacement, and initial obligations are one transaction.
 */
final class SaveEnrollment
{
    /**
     * @param  list<string>  $authorizedBranchCodes
     * @param  array{academic_group_code: string, is_active: bool, observation: ?string}  $attributes
     * @param  list<string>  $shiftCodes
     * @param  list<array{code?: string|null, concept: string, amount: int|float|string, due_date: string}>  $obligations
     */
    public function handle(
        Student $student,
        ?Enrollment $enrollment,
        array $authorizedBranchCodes,
        array $attributes,
        array $shiftCodes,
        array $obligations,
    ): Enrollment {
        try {
            return DB::transaction(function () use (
                $student,
                $enrollment,
                $authorizedBranchCodes,
                $attributes,
                $shiftCodes,
                $obligations,
            ): Enrollment {
                Student::query()->lockForUpdate()->findOrFail($student->code);

                if ($enrollment !== null) {
                    $enrollment = Enrollment::query()
                        ->with('academicGroup.cycleDegree.cycle')
                        ->lockForUpdate()
                        ->findOrFail($enrollment->code);

                    if ($enrollment->student_code !== $student->code) {
                        throw ValidationException::withMessages([
                            'enrollment' => 'La matrícula no pertenece al estudiante.',
                        ]);
                    }

                    $existingBranchCode = $enrollment->academicGroup
                        ->cycleDegree
                        ->cycle
                        ->branch_code;

                    if (! in_array($existingBranchCode, $authorizedBranchCodes, true)) {
                        throw ValidationException::withMessages([
                            'enrollment' => 'No tienes acceso a la sede de esta matrícula.',
                        ]);
                    }
                }

                $group = AcademicGroup::query()
                    ->with('cycleDegree.cycle')
                    ->lockForUpdate()
                    ->findOrFail($attributes['academic_group_code']);
                $cycle = $group->cycleDegree->cycle;

                if (! in_array($cycle->branch_code, $authorizedBranchCodes, true)) {
                    throw ValidationException::withMessages([
                        'academic_group_code' => 'La sección no pertenece a una sede autorizada.',
                    ]);
                }

                if ($attributes['is_active']
                    && (! $group->is_active
                        || ! $cycle->is_active
                        || $cycle->end_date->lt(BusinessCalendar::today()))) {
                    throw ValidationException::withMessages([
                        'academic_group_code' => 'La sección debe pertenecer a un ciclo vigente y activo.',
                    ]);
                }

                $shifts = CycleShift::query()
                    ->whereIn('code', $shiftCodes)
                    ->lockForUpdate()
                    ->get();

                if ($shifts->count() !== count($shiftCodes)
                    || $shifts->contains(fn (CycleShift $shift): bool => $shift->cycle_code !== $cycle->code)) {
                    throw ValidationException::withMessages([
                        'shift_codes' => 'Los turnos deben pertenecer al ciclo de la sección.',
                    ]);
                }

                if ($attributes['is_active']
                    && $shifts->contains(fn (CycleShift $shift): bool => ! $shift->is_active)) {
                    throw ValidationException::withMessages([
                        'shift_codes' => 'Selecciona únicamente turnos activos.',
                    ]);
                }

                if ($attributes['is_active']) {
                    Enrollment::query()
                        ->where('student_code', $student->code)
                        ->when($enrollment !== null, fn ($query) => $query->whereKeyNot($enrollment->code))
                        ->where('is_active', true)
                        ->update(['is_active' => false]);
                }

                $rollCode = $enrollment?->roll_code;

                if ($rollCode === null
                    || ($attributes['is_active'] && Enrollment::query()
                        ->where('roll_code', $rollCode)
                        ->where('is_active', true)
                        ->when($enrollment !== null, fn ($query) => $query->whereKeyNot($enrollment->code))
                        ->exists())) {
                    $rollCode = $this->availableRollCode();
                }

                $enrollmentAttributes = [
                    ...$attributes,
                    'student_code' => $student->code,
                    'roll_code' => $rollCode,
                ];

                if ($enrollment === null) {
                    $enrollment = Enrollment::query()->create($enrollmentAttributes);
                } else {
                    $enrollment->update($enrollmentAttributes);
                }

                $enrollment->shifts()->sync($shiftCodes);
                $this->syncObligations($enrollment, $obligations);

                return $enrollment->refresh()->load([
                    'academicGroup.cycleDegree.cycle.branch',
                    'shifts',
                    'obligations',
                ]);
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505'
                && str_contains($exception->getMessage(), 'enrollments_one_active_per_student_unique')) {
                throw ValidationException::withMessages([
                    'is_active' => 'El estudiante ya tiene otra matrícula activa.',
                ]);
            }

            if ($exception->getCode() === '23505'
                && str_contains($exception->getMessage(), 'enrollments_active_roll_code_unique')) {
                throw ValidationException::withMessages([
                    'roll_code' => 'No se pudo reservar un código de matrícula. Inténtalo nuevamente.',
                ]);
            }

            throw $exception;
        }
    }

    private function availableRollCode(): string
    {
        DB::selectOne(
            "SELECT pg_advisory_xact_lock(hashtext('aeduca.enrollment.roll_code'))",
        );

        $row = DB::selectOne(<<<'SQL'
            SELECT lpad(candidate::text, 4, '0') AS roll_code
            FROM generate_series(1, 9999) AS candidate
            WHERE NOT EXISTS (
                SELECT 1
                FROM enrollments
                WHERE is_active
                  AND roll_code = lpad(candidate::text, 4, '0')
            )
            ORDER BY candidate
            LIMIT 1
            SQL);

        if (! $row || ! is_string($row->roll_code)) {
            throw ValidationException::withMessages([
                'roll_code' => 'No quedan códigos de matrícula disponibles.',
            ]);
        }

        return $row->roll_code;
    }

    /**
     * @param  list<array{code?: string|null, concept: string, amount: int|float|string, due_date: string}>  $obligations
     */
    private function syncObligations(Enrollment $enrollment, array $obligations): void
    {
        $existing = $enrollment->obligations()->lockForUpdate()->get()->keyBy('code');
        $keepCodes = [];

        foreach ($obligations as $obligation) {
            $code = $obligation['code'] ?? null;
            $attributes = [
                'concept' => $obligation['concept'],
                'amount' => $obligation['amount'],
                'due_date' => $obligation['due_date'],
            ];

            if (is_string($code)) {
                $model = $existing->get($code);

                if ($model === null) {
                    throw ValidationException::withMessages([
                        'obligations' => 'Una obligación no pertenece a esta matrícula.',
                    ]);
                }

                $model->update($attributes);
                $keepCodes[] = $model->code;

                continue;
            }

            $keepCodes[] = $enrollment->obligations()->create($attributes)->code;
        }

        $enrollment->obligations()->whereNotIn('code', $keepCodes)->delete();
    }
}
