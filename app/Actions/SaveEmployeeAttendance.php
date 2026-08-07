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

            $slots = $this->todaySlots($employee->code, $branch->code, $date);
            if ($slots === []) {
                throw ValidationException::withMessages([
                    'dni' => 'El usuario no tiene horarios configurados para hoy en esta sede.',
                ]);
            }

            $slot = $this->resolveSlot($slots, $now, enforceWindow: true);
            $this->lockScheduleDay($slot->code, $date);

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
                'user_code' => $employee->code,
                'branch_code' => $branch->code,
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
     *     attendance_date: string,
     *     state?: string|null,
     *     entry_time?: string|null,
     *     observation?: string|null
     * }  $payload
     */
    public function manual(Branch $branch, User $actor, array $payload): EmployeeAttendance
    {
        $operation = (string) $payload['operation'];
        $date = (string) $payload['attendance_date'];
        $now = $this->now();

        return DB::transaction(function () use ($branch, $actor, $payload, $operation, $date, $now): EmployeeAttendance {
            return match ($operation) {
                'create' => $this->manualCreate($branch, $actor, $payload, $date, $now),
                'update' => $this->manualUpdate($branch, $actor, $payload, $date, $now),
                'delete' => $this->manualDelete($branch, $payload),
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
        CarbonImmutable $now,
    ): EmployeeAttendance {
        if ($date !== $now->toDateString()) {
            throw ValidationException::withMessages([
                'attendance_date' => 'Solo se puede registrar asistencia manual del día actual.',
            ]);
        }

        $schedule = EmployeeSchedule::query()
            ->whereKey($payload['schedule_code'] ?? null)
            ->where('branch_code', $branch->code)
            ->lockForUpdate()
            ->first();
        if (! $schedule) {
            throw ValidationException::withMessages([
                'schedule_code' => 'El horario no existe en la sede actual.',
            ]);
        }

        $this->lockScheduleDay($schedule->code, $date);
        if (EmployeeAttendance::query()
            ->where('schedule_code', $schedule->code)
            ->whereDate('attendance_date', $date)
            ->exists()) {
            throw ValidationException::withMessages([
                'schedule_code' => 'Ya existe un registro para este horario hoy.',
            ]);
        }

        $state = EmployeeAttendanceState::from((string) ($payload['state'] ?? ''));
        $entry = $this->normalizeClock((string) ($payload['entry_time'] ?? ''), 'entry_time');

        return EmployeeAttendance::query()->create([
            'user_code' => $schedule->user_code,
            'branch_code' => $branch->code,
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
        CarbonImmutable $now,
    ): EmployeeAttendance {
        if ($date !== $now->toDateString()) {
            throw ValidationException::withMessages([
                'attendance_date' => 'Solo se puede editar asistencia del día actual.',
            ]);
        }

        $fact = EmployeeAttendance::query()
            ->whereKey($payload['attendance_code'] ?? null)
            ->where('branch_code', $branch->code)
            ->lockForUpdate()
            ->first();
        if (! $fact) {
            throw ValidationException::withMessages([
                'attendance_code' => 'El registro no existe.',
            ]);
        }

        $state = EmployeeAttendanceState::from((string) ($payload['state'] ?? ''));
        $entry = $this->normalizeClock((string) ($payload['entry_time'] ?? ''), 'entry_time');

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
    private function manualDelete(Branch $branch, array $payload): EmployeeAttendance
    {
        $fact = EmployeeAttendance::query()
            ->whereKey($payload['attendance_code'] ?? null)
            ->where('branch_code', $branch->code)
            ->lockForUpdate()
            ->first();
        if (! $fact) {
            throw ValidationException::withMessages([
                'attendance_code' => 'El registro no existe.',
            ]);
        }

        $snapshot = $fact;
        $fact->delete();

        return $snapshot;
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
                ORDER BY entry_time ASC
                SQL,
            [$userCode, $branchCode, $date],
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
            return $this->closestSlot($eligible, $minutes);
        }

        if ($enforceWindow) {
            $closest = $this->closestSlot($slots, $minutes);
            $from = substr((string) $closest->entry_time, 0, 5);
            $to = substr((string) $closest->to_time, 0, 5);
            throw ValidationException::withMessages([
                'dni' => "Fuera del rango permitido. Ventana más cercana: {$from}–{$to}.",
            ]);
        }

        return $this->closestSlot($slots, $minutes);
    }

    private function withinWindow(stdClass $slot, int $minutes): bool
    {
        $start = $this->minutesOfDay((string) $slot->entry_time);
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
        $start = $this->minutesOfDay((string) $slot->entry_time);
        $end = $this->minutesOfDay((string) $slot->to_time);
        if ($minutes < $start) {
            return $start - $minutes;
        }
        if ($minutes > $end) {
            return $minutes - $end;
        }

        return 0;
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

    private function lockScheduleDay(string $scheduleCode, string $date): void
    {
        DB::selectOne(
            'SELECT pg_advisory_xact_lock(hashtext(?))',
            ["employee-attendance:{$scheduleCode}:{$date}"],
        );
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
