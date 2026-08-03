<?php

namespace App\Actions;

use App\Models\AcademicCycle;
use App\Models\Branch;
use App\Models\CycleDegree;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Transactional aggregate write for one cycle: attributes, shifts,
 * degrees, and groups. A partial failure never leaves orphaned structure.
 *
 * Invariants protected here:
 * - an existing cycle belongs to the supplied branch;
 * - one or two active shifts per cycle;
 * - shift/group codes only match records already owned by this cycle;
 * - referenced structure is not deleted (clear ValidationException, not raw FK);
 * - attendance-sensitive clocks are frozen after facts exist for the cycle/shift.
 */
final class SaveCycle
{
    /**
     * @param  array{name: string, modality: string, start_date: string, end_date: string, attendance_includes_saturday: bool, is_active: bool}  $attributes
     * @param  list<array{code?: string|null, name: string, entry_time: string, tolerance_minutes: int}>  $shifts
     * @param  list<array{number: int, groups: list<array{code?: string|null, name: string}>}>  $degrees
     */
    public function handle(Branch $branch, ?AcademicCycle $cycle, array $attributes, array $shifts, array $degrees): AcademicCycle
    {
        if ($cycle !== null && $cycle->branch_code !== $branch->code) {
            throw ValidationException::withMessages([
                'cycle' => 'El ciclo no pertenece a la sede actual.',
            ]);
        }

        if ($shifts === [] || count($shifts) > 2) {
            throw new InvalidArgumentException('A cycle requires one or two shifts.');
        }

        return DB::transaction(function () use ($branch, $cycle, $attributes, $shifts, $degrees): AcademicCycle {
            if ($cycle === null) {
                $cycle = $branch->cycles()->create($attributes);
                $this->syncShifts($cycle, $shifts, false);
                $this->syncDegrees($cycle, $degrees);

                return $cycle->refresh();
            }

            $cycle = AcademicCycle::query()->lockForUpdate()->findOrFail($cycle->code);
            $hasFacts = $this->cycleHasAttendanceFacts($cycle->code);
            $attributes = $this->guardAttendanceSensitiveAttributes($cycle, $attributes, $hasFacts);

            $cycle->update($attributes);
            $this->syncShifts($cycle, $shifts, $hasFacts);
            $this->syncDegrees($cycle, $degrees);

            return $cycle->refresh();
        });
    }

    /**
     * @param  array{name: string, modality: string, start_date: string, end_date: string, attendance_includes_saturday: bool, is_active: bool}  $attributes
     * @return array{name: string, modality: string, start_date: string, end_date: string, attendance_includes_saturday: bool, is_active: bool}
     */
    private function guardAttendanceSensitiveAttributes(
        AcademicCycle $cycle,
        array $attributes,
        bool $hasFacts,
    ): array {
        if (! $hasFacts) {
            return $attributes;
        }

        $errors = [];
        $currentStart = $cycle->start_date->toDateString();

        if ($attributes['start_date'] !== $currentStart) {
            $errors['start_date'] = 'No puedes cambiar la fecha de inicio: el ciclo ya tiene asistencia registrada.';
        }

        if ((bool) $attributes['attendance_includes_saturday'] !== (bool) $cycle->attendance_includes_saturday) {
            $errors['attendance_includes_saturday'] = 'No puedes cambiar el sábado lectivo: el ciclo ya tiene asistencia registrada.';
        }

        $newEnd = CarbonImmutable::parse($attributes['end_date'])->startOfDay();
        $oldEnd = $cycle->end_date->startOfDay();

        if ($newEnd->lt($oldEnd)) {
            $errors['end_date'] = 'No puedes acortar la fecha de fin: el ciclo ya tiene asistencia registrada.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $attributes;
    }

    /**
     * @param  list<array{code?: string|null, name: string, entry_time: string, tolerance_minutes: int}>  $shifts
     */
    private function syncShifts(AcademicCycle $cycle, array $shifts, bool $cycleHasFacts): void
    {
        $existing = $cycle->shifts()->get()->keyBy('code');
        $keepCodes = [];

        foreach (array_values($shifts) as $index => $shift) {
            $data = [
                'name' => $shift['name'],
                'entry_time' => $shift['entry_time'],
                'tolerance_minutes' => $shift['tolerance_minutes'],
                'sort_order' => $index,
                'is_active' => true,
            ];

            $code = $shift['code'] ?? null;

            if (is_string($code) && $existing->has($code)) {
                $model = $existing[$code];

                if (
                    $cycleHasFacts
                    && $this->shiftHasAttendanceFacts($code)
                    && (
                        $this->normalizeTime((string) $model->entry_time) !== $this->normalizeTime((string) $data['entry_time'])
                        || (int) $model->tolerance_minutes !== (int) $data['tolerance_minutes']
                    )
                ) {
                    throw ValidationException::withMessages([
                        "shifts.{$index}.entry_time" => 'No puedes cambiar la hora o tolerancia de un turno con asistencia registrada.',
                    ]);
                }

                $model->update($data);
                $keepCodes[] = $code;
            } else {
                $created = $cycle->shifts()->create($data);
                $keepCodes[] = $created->code;
            }
        }

        $toRemove = $existing->keys()->diff($keepCodes)->values()->all();

        if ($toRemove !== []) {
            $this->assertShiftsRemovable($toRemove);
            $cycle->shifts()->whereIn('code', $toRemove)->delete();
        }
    }

    /**
     * @param  list<array{number: int, groups: list<array{code?: string|null, name: string}>}>  $degrees
     */
    private function syncDegrees(AcademicCycle $cycle, array $degrees): void
    {
        $existing = $cycle->degrees()->with('groups')->get()->keyBy('number');
        $keepNumbers = [];

        foreach ($degrees as $degree) {
            $number = (int) $degree['number'];
            $keepNumbers[] = $number;

            $model = $existing->get($number)
                ?? $cycle->degrees()->create(['number' => $number]);

            $this->syncGroups($model, $degree['groups']);
        }

        $toRemove = $existing->keys()->diff($keepNumbers)->values()->all();

        if ($toRemove !== []) {
            $degreeCodes = $existing
                ->only($toRemove)
                ->pluck('code')
                ->all();
            $this->assertDegreesRemovable($degreeCodes);
            $cycle->degrees()->whereIn('number', $toRemove)->delete();
        }
    }

    /**
     * @param  list<array{code?: string|null, name: string}>  $groups
     */
    private function syncGroups(CycleDegree $degree, array $groups): void
    {
        $existing = $degree->groups->keyBy('code');
        $keepCodes = [];

        foreach (array_values($groups) as $index => $group) {
            $data = [
                'name' => $group['name'],
                'sort_order' => $index,
                'is_active' => true,
            ];

            $code = $group['code'] ?? null;

            if (is_string($code) && $existing->has($code)) {
                $existing[$code]->update($data);
                $keepCodes[] = $code;
            } else {
                $created = $degree->groups()->create($data);
                $keepCodes[] = $created->code;
            }
        }

        $toRemove = $existing->keys()->diff($keepCodes)->values()->all();

        if ($toRemove !== []) {
            $this->assertGroupsRemovable($toRemove);
            $degree->groups()->whereIn('code', $toRemove)->delete();
        }
    }

    /**
     * @param  list<string>  $shiftCodes
     */
    private function assertShiftsRemovable(array $shiftCodes): void
    {
        $assigned = DB::table('enrollment_shifts')
            ->whereIn('cycle_shift_code', $shiftCodes)
            ->exists();

        if ($assigned) {
            throw ValidationException::withMessages([
                'shifts' => 'No puedes eliminar un turno asignado a matrículas.',
            ]);
        }
    }

    /**
     * @param  list<string>  $groupCodes
     */
    private function assertGroupsRemovable(array $groupCodes): void
    {
        $used = DB::table('enrollments')
            ->whereIn('academic_group_code', $groupCodes)
            ->exists();

        if ($used) {
            throw ValidationException::withMessages([
                'degrees' => 'No puedes eliminar una sección con matrículas registradas.',
            ]);
        }
    }

    /**
     * @param  list<string>  $degreeCodes
     */
    private function assertDegreesRemovable(array $degreeCodes): void
    {
        $used = DB::table('enrollments as e')
            ->join('academic_groups as g', 'g.code', '=', 'e.academic_group_code')
            ->whereIn('g.cycle_degree_code', $degreeCodes)
            ->exists();

        if ($used) {
            throw ValidationException::withMessages([
                'degrees' => 'No puedes eliminar un grado con matrículas registradas.',
            ]);
        }
    }

    private function cycleHasAttendanceFacts(string $cycleCode): bool
    {
        return DB::table('student_attendances as a')
            ->join('enrollments as e', 'e.code', '=', 'a.enrollment_code')
            ->where('e.cycle_code', $cycleCode)
            ->exists();
    }

    private function shiftHasAttendanceFacts(string $shiftCode): bool
    {
        return DB::table('student_attendances')
            ->where('cycle_shift_code', $shiftCode)
            ->exists();
    }

    private function normalizeTime(string $time): string
    {
        return CarbonImmutable::parse($time)->format('H:i:s');
    }
}
