<?php

namespace App\Actions;

use App\Models\Branch;
use App\Models\StudentAttendance;
use App\Models\User;
use App\Support\Attendance\AttendanceMethod;
use App\Support\Attendance\AttendanceOperation;
use App\Support\Attendance\AttendanceState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Sole write owner for student attendance facts.
 * Expectations stay derived from enrollment + enrollment_shifts + cycle clocks.
 */
final class SaveStudentAttendance
{
    /**
     * @return array<string, mixed>
     */
    public function scan(Branch $branch, User $actor, string $dni): array
    {
        $now = $this->now();
        $date = $now->toDateString();
        $normalizedDni = $this->normalizeDni($dni);

        if ($now->dayOfWeekIso === 7) {
            $this->throwScanUnresolved();
        }

        return DB::transaction(function () use ($branch, $actor, $normalizedDni, $now, $date): array {
            $candidates = $this->scanCandidates($branch->code, $normalizedDni, $date);

            if ($candidates->isEmpty()) {
                $this->throwScanUnresolved();
            }

            // A DNI has no shift selector, so only one tolerance-bounded window may resolve it.
            $open = $candidates->filter(
                fn (stdClass $row): bool => $now->betweenIncluded(
                    $this->opensAt($date, $row->entry_time, (int) $row->tolerance_minutes),
                    $this->closesAt($date, $row->entry_time, (int) $row->tolerance_minutes),
                ),
            );

            if ($open->isEmpty()) {
                throw ValidationException::withMessages([
                    'dni' => 'La lectura está fuera del horario de ingreso permitido.',
                ]);
            }

            if ($open->count() > 1) {
                throw ValidationException::withMessages([
                    'dni' => 'Hay más de un turno abierto para este alumno. Usa el padrón o el registro manual.',
                ]);
            }

            $selected = $open->first();
            $this->lockExpectation($selected->enrollment_code, $selected->cycle_shift_code);

            $fact = StudentAttendance::query()
                ->where('enrollment_code', $selected->enrollment_code)
                ->where('cycle_shift_code', $selected->cycle_shift_code)
                ->whereDate('attendance_date', $date)
                ->lockForUpdate()
                ->first();

            if ($fact) {
                return $this->scanResult($selected, $fact, alreadyRegistered: true);
            }

            $entryAt = $this->entryAt($date, $selected->entry_time);
            $state = $now->lte($entryAt)
                ? AttendanceState::Present
                : AttendanceState::Late;

            $fact = StudentAttendance::query()->create([
                'enrollment_code' => $selected->enrollment_code,
                'cycle_shift_code' => $selected->cycle_shift_code,
                'attendance_date' => $date,
                'state' => $state,
                'arrival_at' => $now,
                'recording_method' => AttendanceMethod::Scan,
                'created_by_user_code' => $actor->code,
                'reason' => null,
            ]);

            return $this->scanResult($selected, $fact, alreadyRegistered: false);
        });
    }

    /**
     * @param  array{
     *     operation: string,
     *     enrollment_code: string,
     *     cycle_shift_code: string,
     *     attendance_date: string,
     *     arrival_at?: string|null,
     *     reason?: string|null,
     *     state?: string|null
     * }  $payload
     */
    public function manual(Branch $branch, User $actor, array $payload): StudentAttendance
    {
        $operation = AttendanceOperation::from($payload['operation']);
        $date = $payload['attendance_date'];
        $now = $this->now();

        if ($this->isSunday($date)) {
            throw ValidationException::withMessages([
                'attendance_date' => 'La asistencia estudiantil se registra de lunes a sábado.',
            ]);
        }

        return DB::transaction(function () use ($branch, $actor, $payload, $operation, $date, $now): StudentAttendance {
            $context = $this->expectationContext(
                $branch->code,
                $payload['enrollment_code'],
                $payload['cycle_shift_code'],
                $date,
            );

            if ($context === null) {
                throw ValidationException::withMessages([
                    'enrollment_code' => 'El alumno no tiene expectativa de asistencia en este contexto.',
                ]);
            }

            $this->lockExpectation($context->enrollment_code, $context->cycle_shift_code);

            $fact = StudentAttendance::query()
                ->where('enrollment_code', $context->enrollment_code)
                ->where('cycle_shift_code', $context->cycle_shift_code)
                ->whereDate('attendance_date', $date)
                ->lockForUpdate()
                ->first();

            if ($fact && $operation !== AttendanceOperation::Correct) {
                throw ValidationException::withMessages([
                    'operation' => 'El registro ya existe. Usa la corrección e indica el motivo.',
                ]);
            }

            $entryAt = $this->entryAt($date, $context->entry_time);
            $closesAt = $this->closesAt($date, $context->entry_time, (int) $context->tolerance_minutes);

            $fact = match ($operation) {
                AttendanceOperation::Arrival => $this->applyArrival(
                    $context,
                    $actor,
                    $date,
                    $payload['arrival_at'] ?? null,
                    $now,
                    $entryAt,
                ),
                AttendanceOperation::Permission => $this->applyPermission(
                    $context,
                    $actor,
                    $date,
                    $payload['reason'] ?? null,
                    $now,
                    $entryAt,
                ),
                AttendanceOperation::Justify => $this->applyJustify(
                    $context,
                    $actor,
                    $date,
                    $payload['reason'] ?? null,
                    $now,
                    $closesAt,
                ),
                AttendanceOperation::Correct => $this->applyCorrect(
                    $fact,
                    $context,
                    $actor,
                    $date,
                    $payload,
                    $now,
                    $entryAt,
                ),
            };

            return $fact;
        });
    }

    private function applyArrival(
        stdClass $context,
        User $actor,
        string $date,
        ?string $arrivalInput,
        CarbonImmutable $now,
        CarbonImmutable $entryAt,
    ): StudentAttendance {
        $arrival = $this->parseArrival($arrivalInput, $date, $now);

        $this->validateArrival($arrival, $date, $now);

        $state = $arrival->lte($entryAt)
            ? AttendanceState::Present
            : AttendanceState::Late;

        return StudentAttendance::query()->create([
            'enrollment_code' => $context->enrollment_code,
            'cycle_shift_code' => $context->cycle_shift_code,
            'attendance_date' => $date,
            'state' => $state,
            'arrival_at' => $arrival,
            'recording_method' => AttendanceMethod::Manual,
            'created_by_user_code' => $actor->code,
            'reason' => null,
        ]);
    }

    private function applyPermission(
        stdClass $context,
        User $actor,
        string $date,
        ?string $reason,
        CarbonImmutable $now,
        CarbonImmutable $entryAt,
    ): StudentAttendance {
        $reason = $this->requireReason($reason);

        if ($date < $now->toDateString()) {
            throw ValidationException::withMessages([
                'operation' => 'El permiso no se registra en fechas pasadas. Usa justificación o corrección.',
            ]);
        }

        if ($date === $now->toDateString() && $now->gte($entryAt)) {
            throw ValidationException::withMessages([
                'operation' => 'El permiso sólo aplica antes de la hora de entrada.',
            ]);
        }

        return StudentAttendance::query()->create([
            'enrollment_code' => $context->enrollment_code,
            'cycle_shift_code' => $context->cycle_shift_code,
            'attendance_date' => $date,
            'state' => AttendanceState::Permission,
            'arrival_at' => null,
            'recording_method' => AttendanceMethod::Manual,
            'created_by_user_code' => $actor->code,
            'reason' => $reason,
        ]);
    }

    private function applyJustify(
        stdClass $context,
        User $actor,
        string $date,
        ?string $reason,
        CarbonImmutable $now,
        CarbonImmutable $closesAt,
    ): StudentAttendance {
        $reason = $this->requireReason($reason);

        if ($date > $now->toDateString()) {
            throw ValidationException::withMessages([
                'operation' => 'La justificación no aplica a fechas futuras.',
            ]);
        }

        if ($date === $now->toDateString() && $now->lte($closesAt)) {
            throw ValidationException::withMessages([
                'operation' => 'La justificación sólo aplica después de cerrar la ventana de ingreso.',
            ]);
        }

        return StudentAttendance::query()->create([
            'enrollment_code' => $context->enrollment_code,
            'cycle_shift_code' => $context->cycle_shift_code,
            'attendance_date' => $date,
            'state' => AttendanceState::Justified,
            'arrival_at' => null,
            'recording_method' => AttendanceMethod::Manual,
            'created_by_user_code' => $actor->code,
            'reason' => $reason,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyCorrect(
        ?StudentAttendance $fact,
        stdClass $context,
        User $actor,
        string $date,
        array $payload,
        CarbonImmutable $now,
        CarbonImmutable $entryAt,
    ): StudentAttendance {
        if (! $fact) {
            throw ValidationException::withMessages([
                'operation' => 'No hay un registro de asistencia para corregir.',
            ]);
        }

        $reason = $this->requireReason($payload['reason'] ?? null);
        $state = AttendanceState::tryFrom((string) ($payload['state'] ?? ''));

        if (! $state) {
            throw ValidationException::withMessages([
                'state' => 'Selecciona un estado válido.',
            ]);
        }

        $arrival = null;
        if ($state === AttendanceState::Present || $state === AttendanceState::Late) {
            $arrival = $this->parseArrival($payload['arrival_at'] ?? null, $date, $now);
            $this->validateArrival($arrival, $date, $now);
            $derived = $arrival->lte($entryAt)
                ? AttendanceState::Present
                : AttendanceState::Late;
            $state = $derived;
        }

        $fact->fill([
            'state' => $state,
            'arrival_at' => $arrival,
            'recording_method' => AttendanceMethod::Manual,
            'reason' => $reason,
            'corrected_by_user_code' => $actor->code,
            'corrected_at' => $now,
        ])->save();

        return $fact->refresh();
    }

    /**
     * @return Collection<int, stdClass>
     */
    private function scanCandidates(string $branchCode, string $dni, string $date)
    {
        return collect(DB::select(
            <<<'SQL'
                SELECT
                    e.code AS enrollment_code,
                    e.roll_code,
                    s.code AS student_code,
                    s.dni,
                    btrim(s.first_name || ' ' || s.last_name) AS full_name,
                    g.name AS group_name,
                    d.number AS degree_number,
                    c.name AS cycle_name,
                    cs.code AS cycle_shift_code,
                    cs.name AS shift_name,
                    cs.entry_time,
                    cs.tolerance_minutes
                FROM students s
                INNER JOIN enrollments e
                    ON e.student_code = s.code
                    AND e.is_active = true
                INNER JOIN academic_cycles c
                    ON c.code = e.cycle_code
                    AND c.branch_code = ?
                    AND c.is_active = true
                    AND c.start_date <= ?::date
                    AND c.end_date >= ?::date
                INNER JOIN academic_groups g
                    ON g.code = e.academic_group_code
                    AND g.is_active = true
                INNER JOIN cycle_degrees d
                    ON d.code = g.cycle_degree_code
                    AND d.cycle_code = c.code
                INNER JOIN enrollment_shifts es
                    ON es.enrollment_code = e.code
                INNER JOIN cycle_shifts cs
                    ON cs.code = es.cycle_shift_code
                    AND cs.cycle_code = c.code
                    AND cs.is_active = true
                WHERE s.dni = ?
                    AND s.is_active = true
                ORDER BY cs.sort_order, cs.entry_time, e.code
                SQL,
            [$branchCode, $date, $date, $dni],
        ));
    }

    private function expectationContext(
        string $branchCode,
        string $enrollmentCode,
        string $shiftCode,
        string $date,
    ): ?stdClass {
        return DB::selectOne(
            <<<'SQL'
                SELECT
                    e.code AS enrollment_code,
                    cs.code AS cycle_shift_code,
                    cs.entry_time,
                    cs.tolerance_minutes
                FROM enrollments e
                INNER JOIN academic_cycles c
                    ON c.code = e.cycle_code
                    AND c.branch_code = ?
                    AND c.is_active = true
                    AND c.start_date <= ?::date
                    AND c.end_date >= ?::date
                INNER JOIN academic_groups g
                    ON g.code = e.academic_group_code
                    AND g.is_active = true
                INNER JOIN cycle_degrees d
                    ON d.code = g.cycle_degree_code
                    AND d.cycle_code = c.code
                INNER JOIN enrollment_shifts es
                    ON es.enrollment_code = e.code
                    AND es.cycle_shift_code = ?
                INNER JOIN cycle_shifts cs
                    ON cs.code = es.cycle_shift_code
                    AND cs.cycle_code = c.code
                    AND cs.is_active = true
                WHERE e.code = ?
                    AND e.is_active = true
                SQL,
            [$branchCode, $date, $date, $shiftCode, $enrollmentCode],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function scanResult(stdClass $context, StudentAttendance $fact, bool $alreadyRegistered): array
    {
        /** @var AttendanceState $state */
        $state = $fact->state;

        return [
            'status' => $alreadyRegistered ? 'already_registered' : 'registered',
            'message' => $alreadyRegistered ? 'Ya registrada' : 'Registrada',
            'student' => [
                'full_name' => $context->full_name,
                'dni' => $context->dni,
            ],
            'enrollment' => [
                'roll_code' => $context->roll_code,
                'cycle_name' => $context->cycle_name,
                'degree_number' => (int) $context->degree_number,
                'group_name' => $context->group_name,
                'shift_name' => $context->shift_name,
            ],
            'attendance' => [
                'state' => $state->value,
                'state_label' => $state->label(),
            ],
        ];
    }

    private function parseArrival(?string $input, string $date, CarbonImmutable $now): CarbonImmutable
    {
        $timezone = $this->timezone();

        if ($input === null || trim($input) === '') {
            return $now;
        }

        try {
            if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', trim($input)) === 1) {
                return CarbonImmutable::parse("{$date} {$input}", $timezone);
            }

            return CarbonImmutable::parse($input, $timezone);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'arrival_at' => 'La hora de llegada no es válida.',
            ]);
        }
    }

    private function requireReason(?string $reason): string
    {
        $normalized = trim((string) $reason);

        if ($normalized === '') {
            throw ValidationException::withMessages([
                'reason' => 'Indica el motivo.',
            ]);
        }

        return $normalized;
    }

    private function validateArrival(CarbonImmutable $arrival, string $date, CarbonImmutable $now): void
    {
        if ($arrival->toDateString() !== $date) {
            throw ValidationException::withMessages([
                'arrival_at' => 'La hora de llegada debe corresponder a la fecha de asistencia.',
            ]);
        }

        if ($arrival->gt($now)) {
            throw ValidationException::withMessages([
                'arrival_at' => 'La hora de llegada no puede ser futura.',
            ]);
        }
    }

    private function normalizeDni(string $dni): string
    {
        $normalized = trim($dni);

        if (preg_match('/^\d{8}$/', $normalized) !== 1) {
            throw ValidationException::withMessages([
                'dni' => 'Ingresa un DNI de ocho dígitos.',
            ]);
        }

        return $normalized;
    }

    private function entryAt(string $date, mixed $entryTime): CarbonImmutable
    {
        $time = $this->normalizeTime($entryTime);

        return CarbonImmutable::parse("{$date} {$time}", $this->timezone());
    }

    private function closesAt(string $date, mixed $entryTime, int $toleranceMinutes): CarbonImmutable
    {
        return $this->entryAt($date, $entryTime)->addMinutes(max($toleranceMinutes, 0));
    }

    private function opensAt(string $date, mixed $entryTime, int $toleranceMinutes): CarbonImmutable
    {
        return $this->entryAt($date, $entryTime)->subMinutes(max($toleranceMinutes, 0));
    }

    private function lockExpectation(string $enrollmentCode, string $shiftCode): void
    {
        $expectation = DB::table('enrollment_shifts')
            ->where('enrollment_code', $enrollmentCode)
            ->where('cycle_shift_code', $shiftCode)
            ->select('enrollment_code')
            ->lockForUpdate()
            ->first();

        if (! $expectation) {
            throw ValidationException::withMessages([
                'enrollment_code' => 'El alumno no tiene expectativa de asistencia en este contexto.',
            ]);
        }
    }

    private function isSunday(string $date): bool
    {
        return CarbonImmutable::parse($date, $this->timezone())->isSunday();
    }

    private function normalizeTime(mixed $entryTime): string
    {
        if ($entryTime instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($entryTime)->format('H:i:s');
        }

        $value = (string) $entryTime;

        if (preg_match('/^\d{2}:\d{2}$/', $value) === 1) {
            return "{$value}:00";
        }

        return $value;
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    private function timezone(): string
    {
        return (string) config('aeduca.business_timezone', 'America/Lima');
    }

    private function throwScanUnresolved(): never
    {
        throw ValidationException::withMessages([
            'dni' => 'No se pudo registrar la lectura con esos datos.',
        ]);
    }
}
