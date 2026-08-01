<?php

namespace App\Http\Controllers;

use App\Actions\SaveStudentAttendance;
use App\Http\Requests\AttendanceManualRequest;
use App\Http\Requests\AttendanceScanRequest;
use App\Models\AcademicCycle;
use App\Models\AuthAccount;
use App\Models\Branch;
use App\Models\Student;
use App\Support\Academic\DegreeNumber;
use App\Support\Attendance\AttendanceState;
use App\Support\Branches\BranchContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    private const int PAGE_SIZE = 20;

    private const string CONTEXT_SESSION_KEY = 'attendance_roster_contexts';

    public function index(Request $request, BranchContext $branchContext): Response|RedirectResponse
    {
        $branch = $this->currentBranch($request, $branchContext);
        if (! $branch) {
            return to_route('branches.index');
        }

        $now = $this->now();
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'cycle' => ['nullable', 'uuid'],
            'degree' => ['nullable', 'integer', 'between:1,6'],
            'group' => ['nullable', 'uuid'],
            'shift' => ['nullable', 'uuid'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $date = (string) ($validated['date'] ?? $now->toDateString());
        $catalog = $this->catalog($branch, $date);
        $filters = [
            'date' => $date,
            'cycle' => (string) ($validated['cycle'] ?? ''),
            'degree' => isset($validated['degree']) ? (string) $validated['degree'] : '',
            'group' => (string) ($validated['group'] ?? ''),
            'shift' => (string) ($validated['shift'] ?? ''),
            'q' => trim((string) ($validated['q'] ?? '')),
        ];

        if (! $request->hasAny(['cycle', 'degree', 'group', 'shift', 'date'])) {
            $remembered = $this->rememberedContext($request, $branch, $catalog);
            if ($remembered) {
                return to_route('attendance.index', array_filter([
                    ...$remembered,
                    'date' => $date,
                    'q' => $filters['q'] !== '' ? $filters['q'] : null,
                ], fn (mixed $value): bool => $value !== null && $value !== ''));
            }
        }

        $contextComplete = $filters['cycle'] !== ''
            && $filters['degree'] !== ''
            && $filters['group'] !== ''
            && $filters['shift'] !== '';
        $contextValid = $contextComplete && $this->contextExists($catalog, $filters);
        abort_if($contextComplete && ! $contextValid, 404);

        $rows = null;
        $summary = $this->emptySummary();

        if ($contextValid) {
            $this->rememberContext($request, $branch, $filters);
            $base = $this->dailyBaseQuery($branch, $filters, $now);
            $summary = $this->dailySummary(clone $base);
            $paginator = (clone $base)
                ->orderBy('full_name')
                ->paginate(self::PAGE_SIZE)
                ->withQueryString();

            $rows = [
                'data' => collect($paginator->items())
                    ->map(fn (object $row): array => $this->mapDailyRow($row))
                    ->all(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ];
        }

        return Inertia::render('Attendance/Index', [
            'attendance' => $rows ?? [
                'data' => [],
                'current_page' => 1,
                'last_page' => 1,
                'total' => 0,
            ],
            'summary' => $summary,
            'filters' => $filters,
            'context_complete' => $contextValid,
            'catalog' => $catalog,
            'business_timezone' => $this->timezone(),
            'can_manage' => Gate::check('attendance.manage'),
            'can_view_profiles' => Gate::check('students.view'),
        ]);
    }

    public function scan(Request $request, BranchContext $branchContext): Response|RedirectResponse
    {
        $branch = $this->currentBranch($request, $branchContext);
        if (! $branch) {
            return to_route('branches.index');
        }

        $now = $this->now();

        return Inertia::render('Attendance/Scan', [
            'branch' => [
                'code' => $branch->code,
                'name' => $branch->name,
            ],
            'business_date' => $now->toDateString(),
            'business_timezone' => $this->timezone(),
        ]);
    }

    public function storeScan(
        AttendanceScanRequest $request,
        BranchContext $branchContext,
        SaveStudentAttendance $saveAttendance,
    ): JsonResponse {
        $branch = $this->currentBranch($request, $branchContext);
        abort_unless($branch, 403);

        /** @var AuthAccount $account */
        $account = $request->user();
        $actor = $account->user;
        abort_unless($actor, 403);

        try {
            $result = $saveAttendance->scan(
                $branch,
                $actor,
                $request->string('dni')->toString(),
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first()
                    ?? 'No se pudo registrar la lectura.',
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json(['result' => $result]);
    }

    public function storeManual(
        AttendanceManualRequest $request,
        BranchContext $branchContext,
        SaveStudentAttendance $saveAttendance,
    ): RedirectResponse {
        $branch = $this->currentBranch($request, $branchContext);
        if (! $branch) {
            return to_route('branches.index');
        }

        /** @var AuthAccount $account */
        $account = $request->user();
        $actor = $account->user;
        abort_unless($actor, 403);

        $saveAttendance->manual($branch, $actor, $request->validated());

        Inertia::flash('success', 'Asistencia actualizada');

        return back();
    }

    public function history(
        Request $request,
        Student $student,
        BranchContext $branchContext,
    ): Response {
        /** @var AuthAccount $account */
        $account = $request->user();
        $isSelf = $account->student_code === $student->code;

        if (! $isSelf) {
            Gate::authorize('attendance.view');
        }

        $now = $this->now();
        $defaultDays = (int) config('aeduca.attendance.history_default_days', 30);
        $maxDays = (int) config('aeduca.attendance.history_max_days', 93);

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $to = (string) ($validated['to'] ?? $now->toDateString());
        $from = (string) ($validated['from'] ?? $now->subDays($defaultDays)->toDateString());
        $fromDate = CarbonImmutable::parse($from, $this->timezone())->startOfDay();
        $toDate = CarbonImmutable::parse($to, $this->timezone())->startOfDay();

        if ($fromDate->gt($toDate)) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        if ($fromDate->diffInDays($toDate) > $maxDays) {
            $fromDate = $toDate->subDays($maxDays);
        }

        $query = DB::table('student_attendances as a')
            ->join('enrollments as e', 'e.code', '=', 'a.enrollment_code')
            ->join('academic_cycles as c', 'c.code', '=', 'e.cycle_code')
            ->join('academic_groups as g', 'g.code', '=', 'e.academic_group_code')
            ->join('cycle_degrees as d', 'd.code', '=', 'g.cycle_degree_code')
            ->join('cycle_shifts as cs', 'cs.code', '=', 'a.cycle_shift_code')
            ->join('branches as b', 'b.code', '=', 'c.branch_code')
            ->where('e.student_code', $student->code)
            ->whereBetween('a.attendance_date', [$fromDate->toDateString(), $toDate->toDateString()])
            ->select([
                'a.code',
                'a.attendance_date',
                'a.state',
                'a.arrival_at',
                'a.reason',
                'e.roll_code',
                'c.name as cycle_name',
                'g.name as group_name',
                'd.number as degree_number',
                'cs.name as shift_name',
                'b.name as branch_name',
            ])
            ->orderByDesc('a.attendance_date')
            ->orderBy('cs.sort_order');

        if (! $isSelf) {
            $branch = $this->currentBranch($request, $branchContext);
            abort_unless($branch, 403);
            $query->where('c.branch_code', $branch->code);
        }

        $paginator = $query->paginate(self::PAGE_SIZE)->withQueryString();

        return Inertia::render('Attendance/History', [
            'student' => [
                'code' => $student->code,
                'full_name' => trim($student->first_name.' '.$student->last_name),
                'dni' => $student->dni,
            ],
            'filters' => [
                'from' => $fromDate->toDateString(),
                'to' => $toDate->toDateString(),
            ],
            'is_self' => $isSelf,
            'business_timezone' => $this->timezone(),
            'history' => [
                'data' => collect($paginator->items())->map(fn (object $row): array => [
                    'code' => $row->code,
                    'attendance_date' => $row->attendance_date,
                    'state' => $row->state,
                    'state_label' => AttendanceState::tryFrom((string) $row->state)?->label() ?? $row->state,
                    'arrival_at' => $row->arrival_at,
                    'reason' => $row->reason,
                    'roll_code' => $row->roll_code,
                    'cycle_name' => $row->cycle_name,
                    'group_name' => $row->group_name,
                    'degree_label' => DegreeNumber::label((int) $row->degree_number),
                    'shift_name' => $row->shift_name,
                    'branch_name' => $row->branch_name,
                ])->all(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function catalog(Branch $branch, string $date): array
    {
        return AcademicCycle::query()
            ->where('branch_code', $branch->code)
            ->active()
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->with([
                'degrees' => fn ($query) => $query->orderBy('number'),
                'degrees.groups' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderBy('sort_order'),
                'shifts' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderBy('sort_order'),
            ])
            ->orderByDesc('start_date')
            ->get(['code', 'name'])
            ->map(fn (AcademicCycle $cycle): array => [
                'code' => $cycle->code,
                'name' => $cycle->name,
                'degrees' => $cycle->degrees->map(fn ($degree): array => [
                    'number' => $degree->number,
                    'label' => DegreeNumber::label($degree->number),
                    'groups' => $degree->groups->map(fn ($group): array => [
                        'code' => $group->code,
                        'name' => $group->name,
                    ])->all(),
                ])->all(),
                'shifts' => $cycle->shifts->map(fn ($shift): array => [
                    'code' => $shift->code,
                    'name' => $shift->name,
                ])->all(),
            ])
            ->all();
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function dailyBaseQuery(Branch $branch, array $filters, CarbonImmutable $now): Builder
    {
        $query = DB::table('enrollments as e')
            ->join('students as s', 's.code', '=', 'e.student_code')
            ->join('academic_cycles as c', function ($join) use ($branch, $filters): void {
                $join->on('c.code', '=', 'e.cycle_code')
                    ->where('c.branch_code', '=', $branch->code)
                    ->where('c.code', '=', $filters['cycle'])
                    ->where('c.is_active', '=', true);
            })
            ->join('academic_groups as g', function ($join) use ($filters): void {
                $join->on('g.code', '=', 'e.academic_group_code')
                    ->where('g.code', '=', $filters['group'])
                    ->where('g.is_active', '=', true);
            })
            ->join('cycle_degrees as d', function ($join) use ($filters): void {
                $join->on('d.code', '=', 'g.cycle_degree_code')
                    ->where('d.cycle_code', '=', DB::raw('c.code'))
                    ->where('d.number', '=', (int) $filters['degree']);
            })
            ->join('enrollment_shifts as es', function ($join) use ($filters): void {
                $join->on('es.enrollment_code', '=', 'e.code')
                    ->where('es.cycle_shift_code', '=', $filters['shift']);
            })
            ->join('cycle_shifts as cs', function ($join) use ($filters): void {
                $join->on('cs.code', '=', 'es.cycle_shift_code')
                    ->where('cs.code', '=', $filters['shift'])
                    ->where('cs.is_active', '=', true);
            })
            ->leftJoin('student_attendances as a', function ($join) use ($filters): void {
                $join->on('a.enrollment_code', '=', 'e.code')
                    ->on('a.cycle_shift_code', '=', 'cs.code')
                    ->whereDate('a.attendance_date', '=', $filters['date']);
            })
            ->where('e.is_active', true)
            ->whereRaw('EXTRACT(ISODOW FROM ?::date) BETWEEN 1 AND 6', [$filters['date']])
            ->whereDate('c.start_date', '<=', $filters['date'])
            ->whereDate('c.end_date', '>=', $filters['date'])
            ->select([
                'e.code as enrollment_code',
                'e.roll_code',
                's.code as student_code',
                's.dni',
                's.first_name',
                's.last_name',
                DB::raw("btrim(s.first_name || ' ' || s.last_name) as full_name"),
                's.is_active as student_is_active',
                'g.name as group_name',
                'cs.code as cycle_shift_code',
                'cs.name as shift_name',
                'a.code as attendance_code',
                'a.state as stored_state',
                'a.arrival_at',
                'a.reason',
            ])
            ->selectRaw(
                'student_attendance_effective_state(a.state, ?::date, cs.entry_time, cs.tolerance_minutes, ?::timestamptz) as effective_state',
                [$filters['date'], $now->toIso8601String()],
            );

        $search = $filters['q'] ?? '';
        if ($search !== '') {
            $normalized = mb_strtolower($search);
            $like = '%'.str_replace(['%', '_'], '', $normalized).'%';
            $query->where(function (Builder $builder) use ($search, $normalized, $like): void {
                $builder
                    ->where('s.dni', $search)
                    ->orWhere('e.roll_code', $search)
                    ->orWhereRaw("lower(btrim(s.first_name || ' ' || s.last_name)) LIKE ?", [$like])
                    ->orWhereRaw("lower(btrim(s.first_name || ' ' || s.last_name)) % ?", [$normalized]);
            });
        }

        return $query;
    }

    /**
     * @return array<string, int>
     */
    private function dailySummary(Builder $base): array
    {
        $row = DB::query()
            ->fromSub($base, 'daily')
            ->selectRaw(<<<'SQL'
                count(*)::int as expected,
                count(*) FILTER (WHERE effective_state = 'present')::int as present,
                count(*) FILTER (WHERE effective_state = 'late')::int as late,
                count(*) FILTER (WHERE effective_state = 'permission')::int as permission,
                count(*) FILTER (WHERE effective_state = 'justified')::int as justified,
                count(*) FILTER (WHERE effective_state = 'pending')::int as pending,
                count(*) FILTER (WHERE effective_state = 'absent')::int as absent
                SQL)
            ->first();

        return [
            'expected' => (int) ($row->expected ?? 0),
            'present' => (int) ($row->present ?? 0),
            'late' => (int) ($row->late ?? 0),
            'permission' => (int) ($row->permission ?? 0),
            'justified' => (int) ($row->justified ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'absent' => (int) ($row->absent ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapDailyRow(object $row): array
    {
        $effective = (string) $row->effective_state;
        $stateLabel = match ($effective) {
            'pending' => 'Pendiente',
            'absent' => 'Falta',
            default => AttendanceState::tryFrom($effective)?->label() ?? $effective,
        };

        return [
            'enrollment_code' => $row->enrollment_code,
            'cycle_shift_code' => $row->cycle_shift_code,
            'student_code' => $row->student_code,
            'dni' => $row->dni,
            'first_name' => $row->first_name,
            'last_name' => $row->last_name,
            'full_name' => $row->full_name,
            'roll_code' => $row->roll_code,
            'student_is_active' => (bool) $row->student_is_active,
            'shift_name' => $row->shift_name,
            'attendance_code' => $row->attendance_code,
            'stored_state' => $row->stored_state,
            'effective_state' => $effective,
            'state_label' => $stateLabel,
            'arrival_at' => $row->arrival_at,
            'reason' => $row->reason,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function emptySummary(): array
    {
        return [
            'expected' => 0,
            'present' => 0,
            'late' => 0,
            'permission' => 0,
            'justified' => 0,
            'pending' => 0,
            'absent' => 0,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $catalog
     * @param  array<string, string>  $filters
     */
    private function contextExists(array $catalog, array $filters): bool
    {
        $cycle = collect($catalog)->firstWhere('code', $filters['cycle']);
        if (! $cycle) {
            return false;
        }

        $degree = collect($cycle['degrees'] ?? [])->first(
            fn (array $item): bool => $item['number'] === (int) $filters['degree'],
        );
        $groupOk = collect($degree['groups'] ?? [])->contains('code', $filters['group']);
        $shiftOk = collect($cycle['shifts'] ?? [])->contains('code', $filters['shift']);

        return $groupOk && $shiftOk;
    }

    /**
     * @param  list<array<string, mixed>>  $catalog
     * @return array{cycle: string, degree: int, group: string, shift: string}|null
     */
    private function rememberedContext(Request $request, Branch $branch, array $catalog): ?array
    {
        $stored = $request->session()->get(self::CONTEXT_SESSION_KEY.'.'.$branch->code);
        if (! is_array($stored)) {
            return null;
        }

        $context = [
            'cycle' => (string) ($stored['cycle'] ?? ''),
            'degree' => (int) ($stored['degree'] ?? 0),
            'group' => (string) ($stored['group'] ?? ''),
            'shift' => (string) ($stored['shift'] ?? ''),
        ];

        $filters = [
            'cycle' => $context['cycle'],
            'degree' => (string) $context['degree'],
            'group' => $context['group'],
            'shift' => $context['shift'],
        ];

        if (! $this->contextExists($catalog, $filters)) {
            $request->session()->forget(self::CONTEXT_SESSION_KEY.'.'.$branch->code);

            return null;
        }

        return $context;
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function rememberContext(Request $request, Branch $branch, array $filters): void
    {
        $request->session()->put(self::CONTEXT_SESSION_KEY.'.'.$branch->code, [
            'cycle' => $filters['cycle'],
            'degree' => (int) $filters['degree'],
            'group' => $filters['group'],
            'shift' => $filters['shift'],
        ]);
    }

    private function currentBranch(Request $request, BranchContext $context): ?Branch
    {
        /** @var AuthAccount $account */
        $account = $request->user();

        return $context->currentBranch($account);
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    private function timezone(): string
    {
        return (string) config('aeduca.business_timezone', 'America/Lima');
    }
}
