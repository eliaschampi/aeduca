<?php

namespace App\Support\EmployeeAttendance;

use App\Models\EmployeeSchedule;
use App\Models\User;
use Illuminate\Support\Collection;

/** Maps schedule rows for profile and admin UIs without duplicating query shape. */
final class EmployeeSchedulePresenter
{
    /**
     * @return list<array{
     *     code: string,
     *     weekday: int,
     *     weekday_label: string,
     *     entry_time: string,
     *     to_time: string,
     *     label: string
     * }>
     */
    public function forUserInBranch(User $employee, string $branchCode): array
    {
        return $this->map(
            EmployeeSchedule::query()
                ->where('user_code', $employee->code)
                ->where('branch_code', $branchCode)
                ->orderBy('weekday')
                ->orderBy('entry_time')
                ->get(),
        );
    }

    /**
     * @param  Collection<int, EmployeeSchedule>  $schedules
     * @return list<array{
     *     code: string,
     *     weekday: int,
     *     weekday_label: string,
     *     entry_time: string,
     *     to_time: string,
     *     label: string
     * }>
     */
    public function map(Collection $schedules): array
    {
        $queries = app(EmployeeAttendanceQueries::class);

        return $schedules
            ->map(function (EmployeeSchedule $schedule) use ($queries): array {
                $entry = $queries->wall($schedule->entry_time);
                $to = $queries->wall($schedule->to_time);
                $weekday = (int) $schedule->weekday;

                return [
                    'code' => $schedule->code,
                    'weekday' => $weekday,
                    'weekday_label' => EmployeeWeekday::label($weekday),
                    'entry_time' => $entry,
                    'to_time' => $to,
                    'label' => sprintf(
                        '%s · %s–%s',
                        EmployeeWeekday::label($weekday),
                        $entry,
                        $to,
                    ),
                ];
            })
            ->values()
            ->all();
    }
}
