<?php

namespace App\Actions;

use App\Models\Branch;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeSchedule;
use App\Models\User;
use App\Support\EmployeeAttendance\EmployeeAttendanceMethod;
use App\Support\EmployeeAttendance\EmployeeAttendanceState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/** Sole write owner for employee attendance facts (scan + manual). */
final class SaveEmployeeAttendance
{
    /** @return array<string, mixed> */
    public function scan(Branch $branch, User $actor, string $dni): array
    {
        $now = $this->now();
        $date = $now->toDateString();
        $dni = $this->normalizeDni($dni);

        return DB::transaction(function () use ($branch, $actor, $dni, $now, $date): array {
            $employee = $this->scannableEmployee($branch->code, $dni);
            if (! $employee) {
                throw ValidationException::withMessages([
                    'dni' => 'No existe un usuario activo con ese DNI en la sede actual.',
                ]);
            }

            $this->lockActiveEmployeeInBranch((string) $employee->code, $branch->code);
            $slots = $this->todaySlots($employee->code, $branch->code, $date);
            if ($slots === []) {
                throw ValidationException::withMessages([
                    'dni' => 'El usuario no tiene horarios configurados para hoy en esta sede.',
                ]);
            }

            $slot = $this->resolveSlot($slots, $now, enforceWindow: true);

            $existing = EmployeeAttendance::query()
                ->where('schedule_code', $slot->code)
                ->whereDate('attendance_date', $date)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $this->scanResult($employee, $slot, $existing, already: true);
            }

            $entryClock = $now->format('H:i:s');
            $state = $this->autoState($entryClock, (string) $slot->entry_time);

            $fact = EmployeeAttendance::query()->create([
                'schedule_code' => $slot->code,
                'attendance_date' => $date,
                'state' => $state,
                'entry_time' => $entryClock,
                'observation' => null,
                'recording_method' => EmployeeAttendanceMethod::Scan,
                'created_by_user_code' => $actor->code,
            ]);

            return $this->scanResult($employee, $slot, $fact, already: false);
        });
    }

    /**
     * @param  array{
     *     operation: string,
     *     schedule_code?: string|null,
     *     attendance_code?: string|null,
     *     state?: string|null,
     *     entry_time?: string|null,
     *     observation?: string|null
     * }  $payload
     */
    public function manual(Branch $branch, User $actor, array $payload): EmployeeAttendance
    {
        $operation = (string) $payload['operation'];
        $date = $this->now()->toDateString();

        return DB::transaction(function () use ($branch, $actor, $payload, $operation, $date): EmployeeAttendance {
            return match ($operation) {
                'create' => $this->manualCreate($branch, $actor, $payload, $date),
                'update' => $this->manualUpdate($branch, $actor, $payload, $date),
                'delete' => $this->manualDelete($branch, $payload, $date),
                default => throw ValidationException::withMessages([
                    'operation' => 'Operación no válida.',
                ]),
            };
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function manualCreate(
        Branch $branch,
        User $actor,
        array $payload,
        string $date,
    ): EmployeeAttendance {
        $schedule = $this->lockScheduleForToday(
            $branch,
            $payload['schedule_code'] ?? null,
            $date,
        );

        if (EmployeeAttendance::query()
            ->where('schedule_code', $schedule->code)
            ->whereDate('attendance_date', $date)
            ->exists()) {
            throw ValidationException::withMessages([
                'schedule_code' => 'Ya existe un registro para este horario hoy.',
            ]);
        }

        $state = EmployeeAttendanceState::from((string) ($payload['state'] ?? ''));
        $entry = $this->entryForState($state, $payload['entry_time'] ?? null);

        return EmployeeAttendance::query()->create([
            'schedule_code' => $schedule->code,
            'attendance_date' => $date,
            'state' => $state,
            'entry_time' => $entry,
            'observation' => $this->nullableText($payload['observation'] ?? null),
            'recording_method' => EmployeeAttendanceMethod::Manual,
            'created_by_user_code' => $actor->code,
            'updated_by_user_code' => $actor->code,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function manualUpdate(
        Branch $branch,
        User $actor,
        array $payload,
        string $date,
    ): EmployeeAttendance {
        $fact = $this->lockFactForToday($branch, $payload['attendance_code'] ?? null, $date);

        $state = EmployeeAttendanceState::from((string) ($payload['state'] ?? ''));
        $entry = $this->entryForState($state, $payload['entry_time'] ?? null);

        $fact->update([
            'state' => $state,
            'entry_time' => $entry,
            'observation' => $this->nullableText($payload['observation'] ?? null),
            'recording_method' => EmployeeAttendanceMethod::Manual,
            'updated_by_user_code' => $actor->code,
        ]);

        return $fact->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function manualDelete(Branch $branch, array $payload, string $date): EmployeeAttendance
    {
        $fact = $this->lockFactForToday($branch, $payload['attendance_code'] ?? null, $date);

        $snapshot = $fact;
        $fact->delete();

        return $snapshot;
    }

    private function lockActiveEmployeeInBranch(string $userCode, string $branchCode): User
    {
        $employee = User::query()
            ->whereKey($userCode)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if (! $employee || ! $employee->branches()->where('branches.code', $branchCode)->exists()) {
            throw ValidationException::withMessages([
                'schedule_code' => 'El usuario no está activo o ya no pertenece a la sede actual.',
            ]);
        }

        return $employee;
    }

    private function lockScheduleForToday(Branch $branch, mixed $scheduleCode, string $date): EmployeeSchedule
    {
        $candidate = EmployeeSchedule::query()
            ->whereKey($scheduleCode)
            ->first(['code', 'user_code']);
        if (! $candidate) {
            throw ValidationException::withMessages([
                'schedule_code' => 'El horario no existe en la sede actual.',
            ]);
        }

        $this->lockActiveEmployeeInBranch($candidate->user_code, $branch->code);

        $schedule = EmployeeSchedule::query()
            ->whereKey($candidate->code)
            ->where('branch_code', $branch->code)
            ->where('weekday', CarbonImmutable::parse($date)->isoWeekday())
            ->whereDate('starts_on', '<=', $date)
            ->where(function ($validity) use ($date): void {
                $validity->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date);
            })
            ->lockForUpdate()
            ->first();
        if (! $schedule) {
            throw ValidationException::withMessages([
                'schedule_code' => 'El horario no está vigente hoy en la sede actual.',
            ]);
        }

        return $schedule;
    }

    private function lockFactForToday(Branch $branch, mixed $attendanceCode, string $date): EmployeeAttendance
    {
        $candidate = EmployeeAttendance::query()
            ->whereKey($attendanceCode)
            ->first(['code', 'schedule_code', 'attendance_date']);
        if (! $candidate || $candidate->attendance_date->toDateString() !== $date) {
            throw ValidationException::withMessages([
                'attendance_code' => 'Solo se puede modificar asistencia del día actual.',
            ]);
        }

        $schedule = $this->lockScheduleForToday($branch, $candidate->schedule_code, $date);
        $fact = EmployeeAttendance::query()
            ->whereKey($candidate->code)
            ->where('schedule_code', $schedule->code)
            ->whereDate('attendance_date', $date)
            ->lockForUpdate()
            ->first();
        if (! $fact) {
            throw ValidationException::withMessages([
                'attendance_code' => 'El registro no existe en la sede actual.',
            ]);
        }

        return $fact;
    }

    /** @return list<stdClass> */
    private function todaySlots(string $userCode, string $branchCode, string $date): array
    {
        return DB::select(
            <<<'SQL'
                SELECT code, entry_time, to_time, weekday
                FROM employee_schedules
                WHERE user_code = ?
                    AND branch_code = ?
                    AND weekday = EXTRACT(ISODOW FROM ?::date)::integer
                    AND starts_on <= ?::date
                    AND (ends_on IS NULL OR ends_on >= ?::date)
                ORDER BY entry_time ASC
                FOR UPDATE
                SQL,
            [$userCode, $branchCode, $date, $date, $date],
        );
    }

    /**
     * @param  list<stdClass>  $slots
     */
    private function resolveSlot(array $slots, CarbonImmutable $now, bool $enforceWindow): stdClass
    {
        $minutes = ((int) $now->format('H')) * 60 + (int) $now->format('i');
        $eligible = array_values(array_filter(
            $slots,
            fn (stdClass $slot): bool => $this->withinWindow($slot, $minutes),
        ));

        if ($eligible !== []) {
            if (count($eligible) > 1) {
                throw ValidationException::withMessages([
                    'dni' => 'Hay más de un horario válido para este momento. Corrige la configuración antes de registrar.',
                ]);
            }

            return $eligible[0];
        }

        if ($enforceWindow) {
            $closest = $this->closestSlot($slots, $minutes);
            $scanFrom = $this->formatMinutes($this->windowStart($closest));
            $from = substr((string) $closest->entry_time, 0, 5);
            $to = substr((string) $closest->to_time, 0, 5);
            throw ValidationException::withMessages([
                'dni' => "Fuera del rango permitido. Marcación más cercana: {$scanFrom}–{$to} (horario {$from}–{$to}).",
            ]);
        }

        return $this->closestSlot($slots, $minutes);
    }

    private function withinWindow(stdClass $slot, int $minutes): bool
    {
        $start = $this->windowStart($slot);
        $end = $this->minutesOfDay((string) $slot->to_time);

        return $minutes >= $start && $minutes <= $end;
    }

    /** @param  list<stdClass>  $slots */
    private function closestSlot(array $slots, int $minutes): stdClass
    {
        usort($slots, function (stdClass $left, stdClass $right) use ($minutes): int {
            $distance = $this->distance($left, $minutes) <=> $this->distance($right, $minutes);
            if ($distance !== 0) {
                return $distance;
            }

            return $this->minutesOfDay((string) $left->entry_time)
                <=> $this->minutesOfDay((string) $right->entry_time);
        });

        return $slots[0];
    }

    private function distance(stdClass $slot, int $minutes): int
    {
        $start = $this->windowStart($slot);
        $end = $this->minutesOfDay((string) $slot->to_time);
        if ($minutes < $start) {
            return $start - $minutes;
        }
        if ($minutes > $end) {
            return $minutes - $end;
        }

        return 0;
    }

    private function windowStart(stdClass $slot): int
    {
        return max(
            0,
            $this->minutesOfDay((string) $slot->entry_time)
                - max(0, (int) config('aeduca.employee_attendance.early_arrival_minutes', 60)),
        );
    }

    private function formatMinutes(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private function minutesOfDay(string $time): int
    {
        $parts = array_map('intval', explode(':', substr($time, 0, 5)));

        return ($parts[0] ?? 0) * 60 + ($parts[1] ?? 0);
    }

    private function autoState(string $arrivalClock, string $expectedEntry): EmployeeAttendanceState
    {
        return $this->minutesOfDay($arrivalClock) > $this->minutesOfDay($expectedEntry)
            ? EmployeeAttendanceState::Late
            : EmployeeAttendanceState::Present;
    }

    private function scannableEmployee(string $branchCode, string $dni): ?stdClass
    {
        return DB::selectOne(
            <<<'SQL'
                SELECT
                    users.code,
                    users.dni,
                    btrim(users.first_name || ' ' || users.last_name) AS full_name,
                    users.photo_path,
                    roles.name AS role_name
                FROM users
                INNER JOIN user_branches membership
                    ON membership.user_code = users.code
                    AND membership.branch_code = ?
                INNER JOIN employee_roles roles ON roles.code = users.employee_role_code
                WHERE users.dni = ?
                    AND users.is_active = true
                SQL,
            [$branchCode, $dni],
        );
    }

    /** @return array<string, mixed> */
    private function scanResult(
        stdClass $employee,
        stdClass $slot,
        EmployeeAttendance $fact,
        bool $already,
    ): array {
        $state = $fact->state instanceof EmployeeAttendanceState
            ? $fact->state
            : EmployeeAttendanceState::from((string) $fact->state);

        return [
            'status' => $already ? 'already_registered' : 'registered',
            'message' => $already ? 'Ya registrada' : 'Registrada',
            'employee' => [
                'code' => (string) $employee->code,
                'dni' => (string) $employee->dni,
                'full_name' => (string) $employee->full_name,
                'role_name' => $employee->role_name !== null ? (string) $employee->role_name : null,
                'photo_path' => $employee->photo_path !== null ? (string) $employee->photo_path : null,
            ],
            'schedule' => [
                'code' => (string) $slot->code,
                'entry_time' => $this->wallTime($slot->entry_time),
                'to_time' => $this->wallTime($slot->to_time),
            ],
            'attendance' => [
                'state' => $state->value,
                'state_label' => $state->label(),
                'entry_time' => $this->wallTime($fact->entry_time),
            ],
        ];
    }

    /** Always HH:mm for JSON — never rely on raw PG/Eloquent time casts. */
    private function wallTime(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->format('H:i');
        }

        $raw = trim((string) $value);
        if (preg_match('/(\d{2}:\d{2})/', $raw, $matches) === 1) {
            return $matches[1];
        }

        return substr($raw, 0, 5);
    }

    private function normalizeDni(string $dni): string
    {
        $dni = trim($dni);
        if (preg_match('/^\d{8}$/', $dni) !== 1) {
            throw ValidationException::withMessages(['dni' => 'Ingresa un DNI de ocho dígitos.']);
        }

        return $dni;
    }

    private function normalizeClock(string $value, string $field): string
    {
        $value = trim($value);
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1) {
            return "{$value}:00";
        }
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $value) === 1) {
            return $value;
        }

        throw ValidationException::withMessages([$field => 'La hora no es válida.']);
    }

    private function entryForState(EmployeeAttendanceState $state, mixed $value): ?string
    {
        if (! $state->recordsArrival()) {
            return null;
        }

        return $this->normalizeClock((string) $value, 'entry_time');
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now(
            (string) config('aeduca.business_timezone', 'America/Lima'),
        );
    }
}
