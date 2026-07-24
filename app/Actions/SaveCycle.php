<?php

namespace App\Actions;

use App\Models\AcademicCycle;
use App\Models\Branch;
use App\Models\CycleDegree;
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
 * - shift/group codes only match records already owned by this cycle.
 */
final class SaveCycle
{
    /**
     * @param  array{name: string, modality: string, start_date: string, end_date: string, is_active: bool}  $attributes
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
            } else {
                $cycle->update($attributes);
            }

            $this->syncShifts($cycle, $shifts);
            $this->syncDegrees($cycle, $degrees);

            return $cycle->refresh();
        });
    }

    /**
     * @param  list<array{code?: string|null, name: string, entry_time: string, tolerance_minutes: int}>  $shifts
     */
    private function syncShifts(AcademicCycle $cycle, array $shifts): void
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
                $existing[$code]->update($data);
                $keepCodes[] = $code;
            } else {
                $created = $cycle->shifts()->create($data);
                $keepCodes[] = $created->code;
            }
        }

        $cycle->shifts()->whereNotIn('code', $keepCodes)->delete();
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

        // Cascade removes groups belonging to deleted degrees.
        $cycle->degrees()->whereNotIn('number', $keepNumbers)->delete();
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

        $degree->groups()->whereNotIn('code', $keepCodes)->delete();
    }
}
