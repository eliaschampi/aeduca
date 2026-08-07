<?php

namespace App\Actions;

use App\Models\Branch;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeSchedule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Create, update, or delete one simple Coedula-style schedule slot. */
final class SaveEmployeeSchedule
{
    /**
     * @param  array{
     *     user_code: string,
     *     branch_code: string,
     *     weekday: int,
     *     entry_time: string,
     *     to_time: string,
     *     schedule_code?: string|null
     * }  $payload
     */
    public function save(User $actor, array $payload): EmployeeSchedule
    {
        return DB::transaction(function () use ($actor, $payload): EmployeeSchedule {
            $employee = User::query()->whereKey($payload['user_code'])->lockForUpdate()->first();
            if (! $employee || ! $employee->is_active) {
                throw ValidationException::withMessages([
                    'user_code' => 'El usuario no está activo o no existe.',
                ]);
            }

            $branch = Branch::query()->active()->find($payload['branch_code']);
            if (! $branch) {
                throw ValidationException::withMessages([
                    'branch_code' => 'La sede no está activa.',
                ]);
            }

            if (! $employee->branches()->where('branches.code', $branch->code)->exists()) {
                throw ValidationException::withMessages([
                    'user_code' => 'El usuario no pertenece a la sede actual.',
                ]);
            }

            $weekday = (int) $payload['weekday'];
            if ($weekday < 1 || $weekday > 7) {
                throw ValidationException::withMessages([
                    'weekday' => 'Selecciona un día válido.',
                ]);
            }

            $entry = $this->normalizeTime((string) $payload['entry_time'], 'entry_time');
            $to = $this->normalizeTime((string) $payload['to_time'], 'to_time');
            if ($to <= $entry) {
                throw ValidationException::withMessages([
                    'to_time' => 'La hora de fin debe ser posterior al inicio.',
                ]);
            }

            $scheduleCode = $payload['schedule_code'] ?? null;
            if (is_string($scheduleCode) && $scheduleCode !== '') {
                $schedule = EmployeeSchedule::query()
                    ->whereKey($scheduleCode)
                    ->where('user_code', $employee->code)
                    ->lockForUpdate()
                    ->first();
                if (! $schedule) {
                    throw ValidationException::withMessages([
                        'schedule_code' => 'El horario no existe.',
                    ]);
                }

                $this->assertSlotIsUnique(
                    $employee->code,
                    $branch->code,
                    $weekday,
                    $entry,
                    $to,
                    $schedule->code,
                );

                $schedule->update([
                    'branch_code' => $branch->code,
                    'weekday' => $weekday,
                    'entry_time' => $entry,
                    'to_time' => $to,
                ]);

                return $schedule->refresh();
            }

            $this->assertSlotIsUnique(
                $employee->code,
                $branch->code,
                $weekday,
                $entry,
                $to,
            );

            return EmployeeSchedule::query()->create([
                'user_code' => $employee->code,
                'branch_code' => $branch->code,
                'weekday' => $weekday,
                'entry_time' => $entry,
                'to_time' => $to,
                'created_by_user_code' => $actor->code,
            ]);
        });
    }

    private function assertSlotIsUnique(
        string $userCode,
        string $branchCode,
        int $weekday,
        string $entry,
        string $to,
        ?string $ignoreScheduleCode = null,
    ): void {
        $query = EmployeeSchedule::query()
            ->where('user_code', $userCode)
            ->where('branch_code', $branchCode)
            ->where('weekday', $weekday)
            ->whereTime('entry_time', $entry)
            ->whereTime('to_time', $to);

        if ($ignoreScheduleCode !== null) {
            $query->whereKeyNot($ignoreScheduleCode);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'entry_time' => 'Ese horario ya está definido para este día (misma ventana Desde–Hasta).',
            ]);
        }
    }

    public function delete(User $actor, EmployeeSchedule $schedule): void
    {
        unset($actor);

        DB::transaction(function () use ($schedule): void {
            if (EmployeeAttendance::query()->where('schedule_code', $schedule->code)->exists()) {
                throw ValidationException::withMessages([
                    'schedule_code' => 'No se puede eliminar un horario con asistencia registrada.',
                ]);
            }

            $schedule->delete();
        });
    }

    private function normalizeTime(string $time, string $field): string
    {
        $time = trim($time);
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1) {
            return "{$time}:00";
        }
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $time) === 1) {
            return $time;
        }

        throw ValidationException::withMessages([
            $field => 'La hora no es válida.',
        ]);
    }
}
