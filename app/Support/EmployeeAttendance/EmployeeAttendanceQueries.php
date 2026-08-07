<?php

namespace App\Support\EmployeeAttendance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/** Shared read queries for daily list and history. */
final class EmployeeAttendanceQueries
{
    /** @return list<stdClass> */
    public function daily(string $branchCode, string $date, CarbonImmutable $now): array
    {
        return DB::select(
            <<<'SQL'
                SELECT
                    es.code AS schedule_code,
                    es.weekday AS schedule_weekday,
                    es.entry_time AS schedule_entry_time,
                    es.to_time AS schedule_to_time,
                    users.code AS user_code,
                    users.dni,
                    btrim(users.first_name || ' ' || users.last_name) AS full_name,
                    users.phone,
                    users.photo_path,
                    roles.name AS role_name,
                    ea.code AS attendance_code,
                    ea.state AS attendance_state,
                    ea.entry_time AS attendance_entry_time,
                    ea.observation AS attendance_observation,
                    employee_attendance_effective_state(
                        ea.state,
                        ?::date,
                        es.to_time,
                        ?::timestamptz
                    ) AS effective_state
                FROM employee_schedules es
                INNER JOIN users ON users.code = es.user_code AND users.is_active = true
                INNER JOIN employee_roles roles ON roles.code = users.employee_role_code
                LEFT JOIN employee_attendances ea
                    ON ea.schedule_code = es.code
                    AND ea.attendance_date = ?::date
                WHERE es.branch_code = ?
                    AND es.weekday = EXTRACT(ISODOW FROM ?::date)::integer
                    AND es.starts_on <= ?::date
                    AND (es.ends_on IS NULL OR es.ends_on >= ?::date)
                ORDER BY es.entry_time ASC, full_name ASC
                SQL,
            [
                $date,
                $now->toIso8601String(),
                $date,
                $branchCode,
                $date,
                $date,
                $date,
            ],
        );
    }

    /** @return list<stdClass> */
    public function history(
        string $userCode,
        string $branchCode,
        string $from,
        string $to,
        CarbonImmutable $now,
    ): array {
        return DB::select(
            <<<'SQL'
                WITH days AS (
                    SELECT generate_series(?::date, ?::date, interval '1 day')::date AS attendance_date
                )
                SELECT
                    days.attendance_date,
                    es.code AS schedule_code,
                    es.weekday AS schedule_weekday,
                    es.entry_time AS schedule_entry_time,
                    es.to_time AS schedule_to_time,
                    ea.code AS attendance_code,
                    ea.state AS attendance_state,
                    ea.entry_time AS attendance_entry_time,
                    ea.observation AS attendance_observation,
                    employee_attendance_effective_state(
                        ea.state,
                        days.attendance_date,
                        es.to_time,
                        ?::timestamptz
                    ) AS effective_state
                FROM days
                INNER JOIN employee_schedules es
                    ON es.user_code = ?
                    AND es.branch_code = ?
                    AND es.weekday = EXTRACT(ISODOW FROM days.attendance_date)::integer
                    AND es.starts_on <= days.attendance_date
                    AND (es.ends_on IS NULL OR es.ends_on >= days.attendance_date)
                LEFT JOIN employee_attendances ea
                    ON ea.schedule_code = es.code
                    AND ea.attendance_date = days.attendance_date
                ORDER BY days.attendance_date DESC, es.entry_time ASC
                SQL,
            [$from, $to, $now->toIso8601String(), $userCode, $branchCode],
        );
    }

    /** @return array<string, mixed> */
    public function mapDailyRow(stdClass $row, ?string $photoUrl = null): array
    {
        $effective = (string) $row->effective_state;

        return [
            'schedule_code' => $row->schedule_code,
            'schedule_weekday' => (int) $row->schedule_weekday,
            'schedule_entry_time' => $this->wall($row->schedule_entry_time),
            'schedule_to_time' => $this->wall($row->schedule_to_time),
            'user_code' => $row->user_code,
            'dni' => $row->dni,
            'full_name' => $row->full_name,
            'phone' => $row->phone,
            'role_name' => $row->role_name,
            'photo_url' => $photoUrl,
            'attendance_code' => $row->attendance_code,
            'attendance_state' => $row->attendance_state,
            'attendance_entry_time' => $row->attendance_entry_time
                ? $this->wall($row->attendance_entry_time)
                : null,
            'attendance_observation' => $row->attendance_observation,
            'effective_state' => $effective,
            'state_label' => EmployeeAttendanceState::effectiveLabel($effective),
        ];
    }

    /** @return array<string, mixed> */
    public function mapHistoryRow(stdClass $row): array
    {
        $effective = (string) $row->effective_state;

        return [
            'attendance_date' => (string) $row->attendance_date,
            'schedule_code' => $row->schedule_code,
            'schedule_weekday' => (int) $row->schedule_weekday,
            'schedule_entry_time' => $this->wall($row->schedule_entry_time),
            'schedule_to_time' => $this->wall($row->schedule_to_time),
            'attendance_code' => $row->attendance_code,
            'attendance_state' => $row->attendance_state,
            'attendance_entry_time' => $row->attendance_entry_time
                ? $this->wall($row->attendance_entry_time)
                : null,
            'attendance_observation' => $row->attendance_observation,
            'effective_state' => $effective,
            'state_label' => EmployeeAttendanceState::effectiveLabel($effective),
        ];
    }

    public function wall(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->format('H:i');
        }

        return substr((string) $value, 0, 5);
    }
}
