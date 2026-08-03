<?php

namespace App\Http\Controllers;

use App\Http\Requests\StudentAttendanceHistoryRequest;
use App\Models\AuthAccount;
use App\Models\Branch;
use App\Models\Student;
use App\Support\Academic\DegreeNumber;
use App\Support\Attendance\AttendanceState;
use App\Support\Branches\BranchContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class StudentAttendanceHistoryController extends Controller
{
    public function __invoke(
        StudentAttendanceHistoryRequest $request,
        Student $student,
        BranchContext $branchContext,
    ): Response|RedirectResponse {
        /** @var AuthAccount $account */
        $account = $request->user();
        $isSelf = $account->student_code === $student->code;
        $branch = null;

        if (! $isSelf) {
            Gate::authorize('attendance.view');
            $branch = $branchContext->currentBranch($account);

            if (! $branch) {
                return to_route('branches.index');
            }
        }

        $now = CarbonImmutable::now($this->timezone());
        $today = $now->startOfDay();
        $defaultDays = max(1, (int) config('aeduca.attendance.history_default_days', 30));
        $maxDays = max(1, (int) config('aeduca.attendance.history_max_days', 93));
        $enrollments = $this->enrollmentContexts(
            $student->code,
            $branch,
            $today,
            $defaultDays,
            $maxDays,
        );
        $validated = $request->validated();
        $requestedEnrollment = $validated['enrollment'] ?? null;
        $selected = $requestedEnrollment
            ? $enrollments->firstWhere('code', $requestedEnrollment)
            : $enrollments->first();

        if ($requestedEnrollment && ! $selected) {
            abort(404);
        }

        if (! $selected) {
            $range = $this->normalizeRange(
                $today->subDays($defaultDays - 1),
                $today,
                $today,
                $maxDays,
            );

            return $this->render(
                $student,
                $isSelf,
                $enrollments,
                [
                    'enrollment' => '',
                    'shift' => '',
                    'from' => $range['from'],
                    'to' => $range['to'],
                ],
                [],
            );
        }

        $shifts = collect($selected['shifts']);
        $requestedShift = $validated['shift'] ?? null;
        $selectedShift = $requestedShift
            ? $shifts->firstWhere('code', $requestedShift)
            : $shifts->first();

        if ($requestedShift && ! $selectedShift) {
            abort(404);
        }

        $range = $this->requestedRange($validated, $selected, $today, $maxDays);

        $history = $selectedShift
            ? $this->history(
                $selected['code'],
                $selectedShift['code'],
                $range['from'],
                $range['to'],
                $now,
            )
            : [];

        return $this->render(
            $student,
            $isSelf,
            $enrollments,
            [
                'enrollment' => $selected['code'],
                'shift' => $selectedShift['code'] ?? '',
                'from' => $range['from'],
                'to' => $range['to'],
            ],
            $history,
        );
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function enrollmentContexts(
        string $studentCode,
        ?Branch $branch,
        CarbonImmutable $today,
        int $defaultDays,
        int $maxDays,
    ): Collection {
        $rows = DB::table('enrollments as e')
            ->join('academic_cycles as c', 'c.code', '=', 'e.cycle_code')
            ->join('branches as b', 'b.code', '=', 'c.branch_code')
            ->join('academic_groups as g', 'g.code', '=', 'e.academic_group_code')
            ->join('cycle_degrees as d', function ($join): void {
                $join->on('d.code', '=', 'g.cycle_degree_code')
                    ->on('d.cycle_code', '=', 'c.code');
            })
            ->where('e.student_code', $studentCode)
            ->when($branch, fn ($query, Branch $current) => $query->where('c.branch_code', $current->code))
            ->select([
                'e.code',
                'e.roll_code',
                'e.is_active',
                'e.attendance_starts_on',
                'b.code as branch_code',
                'b.name as branch_name',
                'c.code as cycle_code',
                'c.name as cycle_name',
                'c.start_date as cycle_start_date',
                'c.end_date as cycle_end_date',
                'c.attendance_includes_saturday',
                'd.number as degree_number',
                'g.name as group_name',
            ])
            ->orderByDesc('e.is_active')
            ->orderByDesc('c.start_date')
            ->orderBy('e.code')
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $shifts = DB::table('enrollment_shifts as es')
            ->join('cycle_shifts as cs', 'cs.code', '=', 'es.cycle_shift_code')
            ->whereIn('es.enrollment_code', $rows->pluck('code'))
            ->select([
                'es.enrollment_code',
                'cs.code',
                'cs.name',
                'cs.sort_order',
            ])
            ->orderBy('es.enrollment_code')
            ->orderBy('cs.sort_order')
            ->orderBy('cs.code')
            ->get()
            ->groupBy('enrollment_code');

        return $rows->map(function (object $row) use ($shifts, $today, $defaultDays, $maxDays): array {
            $expectationStart = max(
                (string) $row->cycle_start_date,
                (string) $row->attendance_starts_on,
            );
            $range = $this->defaultRange(
                $expectationStart,
                (string) $row->cycle_end_date,
                $today,
                $defaultDays,
                $maxDays,
            );

            return [
                'code' => $row->code,
                'roll_code' => $row->roll_code,
                'is_active' => (bool) $row->is_active,
                'branch_code' => $row->branch_code,
                'branch_name' => $row->branch_name,
                'cycle_code' => $row->cycle_code,
                'cycle_name' => $row->cycle_name,
                'cycle_start_date' => (string) $row->cycle_start_date,
                'cycle_end_date' => (string) $row->cycle_end_date,
                'attendance_starts_on' => (string) $row->attendance_starts_on,
                'attendance_includes_saturday' => (bool) $row->attendance_includes_saturday,
                'degree_label' => DegreeNumber::label((int) $row->degree_number),
                'group_name' => $row->group_name,
                'shifts' => $shifts->get($row->code, collect())
                    ->map(fn (object $shift): array => [
                        'code' => $shift->code,
                        'name' => $shift->name,
                    ])
                    ->values()
                    ->all(),
                'default_from' => $range['from'],
                'default_to' => $range['to'],
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $selected
     * @return array{from: string, to: string}
     */
    private function requestedRange(
        array $validated,
        array $selected,
        CarbonImmutable $today,
        int $maxDays,
    ): array {
        $from = CarbonImmutable::parse(
            (string) ($validated['from'] ?? $selected['default_from']),
            $this->timezone(),
        )->startOfDay();
        $to = CarbonImmutable::parse(
            (string) ($validated['to'] ?? $selected['default_to']),
            $this->timezone(),
        )->startOfDay();
        $expectationStart = CarbonImmutable::parse(
            max(
                (string) $selected['cycle_start_date'],
                (string) ($selected['attendance_starts_on'] ?? $selected['cycle_start_date']),
            ),
            $this->timezone(),
        )->startOfDay();
        $cycleEnd = CarbonImmutable::parse((string) $selected['cycle_end_date'], $this->timezone())->startOfDay();

        return $this->normalizeRange($from, $to, $today, $maxDays, $expectationStart, $cycleEnd);
    }

    /**
     * @return array{from: string, to: string}
     */
    private function defaultRange(
        string $cycleStart,
        string $cycleEnd,
        CarbonImmutable $today,
        int $defaultDays,
        int $maxDays,
    ): array {
        $start = CarbonImmutable::parse($cycleStart, $this->timezone())->startOfDay();
        $end = CarbonImmutable::parse($cycleEnd, $this->timezone())->startOfDay();
        $to = $end->lt($today) ? $end : $today;
        $candidateFrom = $to->subDays($defaultDays - 1);
        $from = $start->gt($candidateFrom) ? $start : $candidateFrom;

        return $this->normalizeRange($from, $to, $today, $maxDays, $start, $end);
    }

    /**
     * @return array{from: string, to: string}
     */
    private function normalizeRange(
        CarbonImmutable $from,
        CarbonImmutable $to,
        CarbonImmutable $today,
        int $maxDays,
        ?CarbonImmutable $cycleStart = null,
        ?CarbonImmutable $cycleEnd = null,
    ): array {
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $allowedEnd = $today;

        if ($cycleEnd !== null && $cycleEnd->lt($allowedEnd)) {
            $allowedEnd = $cycleEnd;
        }

        if ($to->gt($allowedEnd)) {
            $to = $allowedEnd;
        }

        if ($cycleStart !== null && $from->lt($cycleStart)) {
            $from = $cycleStart;
        }

        // Empty intersection with the cycle/today window: pin to the last allowed day.
        if ($from->gt($to)) {
            $from = $to = $allowedEnd;
        }

        if (((int) $from->diffInDays($to)) + 1 > $maxDays) {
            $from = $to->subDays($maxDays - 1);

            if ($cycleStart !== null && $from->lt($cycleStart)) {
                $from = $cycleStart;
            }
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(
        string $enrollmentCode,
        string $shiftCode,
        string $from,
        string $to,
        CarbonImmutable $referenceNow,
    ): array {
        return DB::query()
            ->fromRaw(
                <<<'SQL'
                    (
                        SELECT
                            e.code AS enrollment_code,
                            cs.code AS cycle_shift_code,
                            cs.entry_time,
                            cs.tolerance_minutes,
                            cs.sort_order AS shift_sort_order,
                            days.attendance_date::date AS attendance_date
                        FROM enrollments e
                        INNER JOIN academic_cycles c
                            ON c.code = e.cycle_code
                        INNER JOIN enrollment_shifts es
                            ON es.enrollment_code = e.code
                        INNER JOIN cycle_shifts cs
                            ON cs.code = es.cycle_shift_code
                            AND cs.cycle_code = c.code
                        CROSS JOIN LATERAL generate_series(
                            GREATEST(?::date, c.start_date, e.attendance_starts_on),
                            LEAST(?::date, c.end_date),
                            INTERVAL '1 day'
                        ) AS days(attendance_date)
                        WHERE e.code = ?::uuid
                            AND cs.code = ?::uuid
                            AND student_attendance_is_expected_day(
                                days.attendance_date::date,
                                c.attendance_includes_saturday
                            )
                    ) AS expected
                    SQL,
                [$from, $to, $enrollmentCode, $shiftCode],
            )
            ->leftJoin('student_attendances as a', function ($join): void {
                $join->on('a.enrollment_code', '=', 'expected.enrollment_code')
                    ->on('a.cycle_shift_code', '=', 'expected.cycle_shift_code')
                    ->on('a.attendance_date', '=', 'expected.attendance_date');
            })
            ->select([
                'a.code as attendance_code',
                'expected.enrollment_code',
                'expected.cycle_shift_code',
                'expected.attendance_date',
                'a.state as stored_state',
                'a.arrival_at',
                'a.reason',
            ])
            ->selectRaw(
                <<<'SQL'
                    student_attendance_effective_state(
                        a.state,
                        expected.attendance_date,
                        expected.entry_time,
                        expected.tolerance_minutes,
                        ?::timestamptz
                    ) AS effective_state
                    SQL,
                [$referenceNow->toIso8601String()],
            )
            ->orderByDesc('expected.attendance_date')
            ->get()
            ->map(fn (object $row): array => $this->historyRow($row))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function historyRow(object $row): array
    {
        $effectiveState = (string) $row->effective_state;

        return [
            'attendance_code' => $row->attendance_code,
            'enrollment_code' => $row->enrollment_code,
            'cycle_shift_code' => $row->cycle_shift_code,
            'attendance_date' => (string) $row->attendance_date,
            'stored_state' => $row->stored_state,
            'effective_state' => $effectiveState,
            'state_label' => AttendanceState::effectiveLabel($effectiveState),
            'arrival_at' => $row->arrival_at,
            'reason' => $row->reason,
            'is_derived' => $row->attendance_code === null,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $enrollments
     * @param  array{enrollment: string, shift: string, from: string, to: string}  $filters
     * @param  list<array<string, mixed>>  $history
     */
    private function render(
        Student $student,
        bool $isSelf,
        Collection $enrollments,
        array $filters,
        array $history,
    ): Response {
        return Inertia::render('Attendance/History', [
            'student' => [
                'code' => $student->code,
                'full_name' => trim($student->first_name.' '.$student->last_name),
                'dni' => $student->dni,
            ],
            'enrollments' => $enrollments->values()->all(),
            'filters' => $filters,
            'is_self' => $isSelf,
            'business_timezone' => $this->timezone(),
            'history' => $history,
        ]);
    }

    private function timezone(): string
    {
        return (string) config('aeduca.business_timezone', 'America/Lima');
    }
}
