<?php

namespace App\Actions;

use App\Models\Branch;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
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
            $today = $this->businessDate();
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
                    ->where('branch_code', $branch->code)
                    ->whereNull('ends_on')
                    ->lockForUpdate()
                    ->first();
                if (! $schedule) {
                    throw ValidationException::withMessages([
                        'schedule_code' => 'El horario no existe.',
                    ]);
                }

                if (
                    (int) $schedule->weekday === $weekday
                    && $this->wallTime($schedule->entry_time) === $entry
                    && $this->wallTime($schedule->to_time) === $to
                ) {
                    return $schedule;
                }

                if (! $this->hasHistoricalMeaning($schedule, $today)) {
                    $this->assertNoOverlap(
                        $employee->code,
                        $branch->code,
                        $weekday,
                        $entry,
                        $to,
                        $schedule->starts_on->toDateString(),
                        $schedule->ends_on?->toDateString(),
                        $schedule->code,
                    );

                    $schedule->update([
                        'weekday' => $weekday,
                        'entry_time' => $entry,
                        'to_time' => $to,
                    ]);

                    return $schedule->refresh();
                }

                $hasTodayFact = EmployeeAttendance::query()
                    ->where('schedule_code', $schedule->code)
                    ->whereDate('attendance_date', $today->toDateString())
                    ->exists();
                $replacementStarts = $hasTodayFact ? $today->addDay() : $today;
                $schedule->update(['ends_on' => $replacementStarts->subDay()->toDateString()]);

                $this->assertNoOverlap(
                    $employee->code,
                    $branch->code,
                    $weekday,
                    $entry,
                    $to,
                    $replacementStarts->toDateString(),
                    null,
                );

                return EmployeeSchedule::query()->create([
                    'user_code' => $employee->code,
                    'branch_code' => $branch->code,
                    'weekday' => $weekday,
                    'entry_time' => $entry,
                    'to_time' => $to,
                    'starts_on' => $replacementStarts->toDateString(),
                    'ends_on' => null,
                    'created_by_user_code' => $actor->code,
                ]);
            }

            $this->assertNoOverlap(
                $employee->code,
                $branch->code,
                $weekday,
                $entry,
                $to,
                $today->toDateString(),
                null,
            );

            return EmployeeSchedule::query()->create([
                'user_code' => $employee->code,
                'branch_code' => $branch->code,
                'weekday' => $weekday,
                'entry_time' => $entry,
                'to_time' => $to,
                'starts_on' => $today->toDateString(),
                'ends_on' => null,
                'created_by_user_code' => $actor->code,
            ]);
        });
    }

    private function assertNoOverlap(
        string $userCode,
        string $branchCode,
        int $weekday,
        string $entry,
        string $to,
        string $startsOn,
        ?string $endsOn,
        ?string $ignoreScheduleCode = null,
    ): void {
        $query = EmployeeSchedule::query()
            ->where('user_code', $userCode)
            ->where('branch_code', $branchCode)
            ->where('weekday', $weekday)
            ->where(function ($validity) use ($startsOn): void {
                $validity->whereNull('ends_on')->orWhereDate('ends_on', '>=', $startsOn);
            });

        if ($endsOn !== null) {
            $query->whereDate('starts_on', '<=', $endsOn);
        }

        if ($ignoreScheduleCode !== null) {
            $query->whereKeyNot($ignoreScheduleCode);
        }

        $earlyArrivalMinutes = max(
            0,
            (int) config('aeduca.employee_attendance.early_arrival_minutes', 60),
        );
        $candidateStart = max(0, $this->minutesOfDay($entry) - $earlyArrivalMinutes);
        $candidateEnd = $this->minutesOfDay($to);
        $overlaps = $query
            ->get(['entry_time', 'to_time'])
            ->contains(function (EmployeeSchedule $schedule) use (
                $candidateStart,
                $candidateEnd,
                $earlyArrivalMinutes,
            ): bool {
                $existingStart = max(
                    0,
                    $this->minutesOfDay($this->wallTime($schedule->entry_time))
                        - $earlyArrivalMinutes,
                );
                $existingEnd = $this->minutesOfDay($this->wallTime($schedule->to_time));

                return $existingStart <= $candidateEnd && $existingEnd >= $candidateStart;
            });

        if ($overlaps) {
            throw ValidationException::withMessages([
                'entry_time' => 'La ventana de marcación se superpone con otro horario vigente de este día.',
            ]);
        }
    }

    public function delete(User $actor, EmployeeSchedule $schedule): void
    {
        unset($actor);

        DB::transaction(function () use ($schedule): void {
            User::query()->whereKey($schedule->user_code)->lockForUpdate()->firstOrFail();
            $locked = EmployeeSchedule::query()
                ->whereKey($schedule->code)
                ->where('user_code', $schedule->user_code)
                ->where('branch_code', $schedule->branch_code)
                ->whereNull('ends_on')
                ->lockForUpdate()
                ->firstOrFail();
            $today = $this->businessDate();

            if (! $this->hasHistoricalMeaning($locked, $today)) {
                $locked->delete();

                return;
            }

            $hasTodayFact = EmployeeAttendance::query()
                ->where('schedule_code', $locked->code)
                ->whereDate('attendance_date', $today->toDateString())
                ->exists();
            $locked->update([
                'ends_on' => ($hasTodayFact ? $today : $today->subDay())->toDateString(),
            ]);
        });
    }

    private function hasHistoricalMeaning(
        EmployeeSchedule $schedule,
        CarbonImmutable $today,
    ): bool {
        if (EmployeeAttendance::query()->where('schedule_code', $schedule->code)->exists()) {
            return true;
        }

        $startsOn = CarbonImmutable::instance($schedule->starts_on)->startOfDay();
        $daysUntilWeekday = ((int) $schedule->weekday - $startsOn->isoWeekday() + 7) % 7;

        return $startsOn->addDays($daysUntilWeekday)->lt($today);
    }

    private function businessDate(): CarbonImmutable
    {
        return CarbonImmutable::now(
            (string) config('aeduca.business_timezone', 'America/Lima'),
        )->startOfDay();
    }

    private function wallTime(mixed $value): string
    {
        return substr((string) $value, 0, 8);
    }

    private function minutesOfDay(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));

        return $hour * 60 + $minute;
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
