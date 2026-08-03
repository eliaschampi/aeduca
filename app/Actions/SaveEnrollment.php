<?php

namespace App\Actions;

use App\Models\AcademicGroup;
use App\Models\Branch;
use App\Models\CycleShift;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\StudentAttendance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveEnrollment
{
    /**
     * @param  list<string>  $shiftCodes
     */
    public function handle(
        Branch $branch,
        Student $student,
        ?Enrollment $enrollment,
        string $academicGroupCode,
        array $shiftCodes,
        bool $isActive,
        ?string $observation,
        ?string $attendanceStartsOn = null,
    ): Enrollment {
        return DB::transaction(function () use (
            $branch,
            $student,
            $enrollment,
            $academicGroupCode,
            $shiftCodes,
            $isActive,
            $observation,
            $attendanceStartsOn,
        ): Enrollment {
            Student::query()->lockForUpdate()->findOrFail($student->code);

            $group = AcademicGroup::query()
                ->with('cycleDegree.cycle')
                ->find($academicGroupCode);
            $cycle = $group?->cycleDegree?->cycle;
            $today = CarbonImmutable::now(config('aeduca.business_timezone'))->startOfDay();

            if (! $group || ! $cycle || $cycle->branch_code !== $branch->code) {
                throw ValidationException::withMessages([
                    'academic_group_code' => 'La sección no pertenece a la sede actual.',
                ]);
            }

            if (
                ! $group->is_active
                || ! $cycle->is_active
                || $cycle->end_date->startOfDay()->isBefore($today)
            ) {
                throw ValidationException::withMessages([
                    'academic_group_code' => 'La sección debe pertenecer a un ciclo vigente.',
                ]);
            }

            if ($enrollment) {
                $enrollment = Enrollment::query()
                    ->with('cycle')
                    ->lockForUpdate()
                    ->findOrFail($enrollment->code);

                if ($enrollment->student_code !== $student->code) {
                    throw ValidationException::withMessages([
                        'enrollment' => 'La matrícula no pertenece al alumno seleccionado.',
                    ]);
                }

                if (
                    $enrollment->cycle?->branch_code !== $branch->code
                ) {
                    throw ValidationException::withMessages([
                        'enrollment' => 'La matrícula no pertenece a la sede actual.',
                    ]);
                }

                if ($enrollment->cycle_code !== $cycle->code) {
                    throw ValidationException::withMessages([
                        'academic_group_code' => 'Una matrícula no puede cambiar de ciclo.',
                    ]);
                }
            } else {
                $existingEnrollment = Enrollment::query()
                    ->where('student_code', $student->code)
                    ->where('cycle_code', $cycle->code)
                    ->first();

                if ($existingEnrollment) {
                    throw ValidationException::withMessages([
                        'enrollment' => 'El alumno ya tiene una matrícula en este ciclo. Edita la matrícula existente.',
                    ]);
                }

                $hasOpenEnrollment = Enrollment::query()
                    ->join('academic_cycles', 'academic_cycles.code', '=', 'enrollments.cycle_code')
                    ->where('enrollments.student_code', $student->code)
                    ->whereDate('academic_cycles.end_date', '>=', $today->toDateString())
                    ->exists();

                if ($hasOpenEnrollment) {
                    throw ValidationException::withMessages([
                        'enrollment' => 'El alumno ya pertenece a un ciclo vigente. Edita esa matrícula antes de continuar.',
                    ]);
                }
            }

            $uniqueShiftCodes = array_values(array_unique($shiftCodes));
            $shifts = CycleShift::query()
                ->whereIn('code', $uniqueShiftCodes)
                ->get(['code', 'cycle_code', 'is_active']);

            if (
                count($uniqueShiftCodes) < 1
                || count($uniqueShiftCodes) > 2
                || $shifts->count() !== count($uniqueShiftCodes)
                || $shifts->contains(fn (CycleShift $shift): bool => (
                    $shift->cycle_code !== $cycle->code
                    || ! $shift->is_active
                ))
            ) {
                throw ValidationException::withMessages([
                    'shift_codes' => 'Selecciona uno o dos turnos activos del mismo ciclo.',
                ]);
            }

            if ($enrollment) {
                $assignedShiftCodes = DB::table('enrollment_shifts')
                    ->where('enrollment_code', $enrollment->code)
                    ->lockForUpdate()
                    ->pluck('cycle_shift_code')
                    ->all();
                $removedShiftCodes = array_values(array_diff($assignedShiftCodes, $uniqueShiftCodes));

                if (
                    $removedShiftCodes !== []
                    && StudentAttendance::query()
                        ->where('enrollment_code', $enrollment->code)
                        ->whereIn('cycle_shift_code', $removedShiftCodes)
                        ->exists()
                ) {
                    throw ValidationException::withMessages([
                        'shift_codes' => 'No puedes quitar un turno que ya tiene asistencia registrada.',
                    ]);
                }
            }

            $startsOn = $this->resolveAttendanceStartsOn(
                CarbonImmutable::parse($cycle->start_date->toDateString())->startOfDay(),
                CarbonImmutable::parse($cycle->end_date->toDateString())->startOfDay(),
                $attendanceStartsOn,
                $enrollment,
            );

            if (! $enrollment) {
                $enrollment = new Enrollment([
                    'student_code' => $student->code,
                    'cycle_code' => $cycle->code,
                    'roll_code' => $this->reserveRollCode($cycle->code),
                ]);
            }

            $enrollment->fill([
                'academic_group_code' => $group->code,
                'attendance_starts_on' => $startsOn->toDateString(),
                'is_active' => $isActive,
                'observation' => $observation,
            ])->save();

            $enrollment->shifts()->sync($uniqueShiftCodes);

            return $enrollment;
        });
    }

    private function resolveAttendanceStartsOn(
        CarbonImmutable $cycleStart,
        CarbonImmutable $cycleEnd,
        ?string $attendanceStartsOn,
        ?Enrollment $enrollment,
    ): CarbonImmutable {
        if ($attendanceStartsOn === null || trim($attendanceStartsOn) === '') {
            if ($enrollment?->attendance_starts_on) {
                return CarbonImmutable::parse(
                    $enrollment->attendance_starts_on->toDateString(),
                )->startOfDay();
            }

            return $cycleStart;
        }

        try {
            $startsOn = CarbonImmutable::parse($attendanceStartsOn)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'attendance_starts_on' => 'Indica una fecha de inicio de asistencia válida.',
            ]);
        }

        if ($startsOn->lt($cycleStart) || $startsOn->gt($cycleEnd)) {
            throw ValidationException::withMessages([
                'attendance_starts_on' => 'La asistencia debe iniciar dentro de las fechas del ciclo.',
            ]);
        }

        if ($enrollment) {
            $earliestFact = StudentAttendance::query()
                ->where('enrollment_code', $enrollment->code)
                ->min('attendance_date');

            if (
                is_string($earliestFact)
                && $startsOn->gt(CarbonImmutable::parse($earliestFact)->startOfDay())
            ) {
                throw ValidationException::withMessages([
                    'attendance_starts_on' => 'No puedes iniciar la asistencia después de fechas ya registradas.',
                ]);
            }
        }

        return $startsOn;
    }

    private function reserveRollCode(string $cycleCode): string
    {
        $result = DB::selectOne(
            'SELECT reserve_enrollment_roll_code(?) AS roll_code',
            [$cycleCode],
        );
        $rollCode = $result?->roll_code ?? null;

        if (! is_string($rollCode)) {
            throw new \RuntimeException('No se pudo reservar el código de matrícula.');
        }

        return trim($rollCode);
    }
}
