<?php

namespace App\Http\Controllers;

use App\Actions\SaveEnrollment;
use App\Http\Requests\EnrollmentRequest;
use App\Http\Requests\EnrollmentStateRequest;
use App\Http\Requests\RosterRequest;
use App\Models\AcademicCycle;
use App\Models\AuthAccount;
use App\Models\Branch;
use App\Models\Enrollment;
use App\Models\Student;
use App\Support\Academic\DegreeNumber;
use App\Support\Branches\BranchContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EnrollmentController extends Controller
{
    private const ROSTER_CONTEXT_SESSION_KEY = 'student_roster_contexts';

    public function index(
        RosterRequest $request,
        BranchContext $context,
    ): Response|RedirectResponse {
        $branch = $this->currentBranch($request, $context);
        if (! $branch) {
            return to_route('branches.index');
        }

        $filters = $request->validated();
        $catalog = $this->filterCatalog($branch);
        if (! $request->hasAny(['cycle', 'degree', 'group'])) {
            $rememberedContext = $this->rememberedRosterContext(
                $request,
                $branch,
                $catalog,
            );

            if ($rememberedContext) {
                return to_route('enrollments.index', array_filter([
                    ...$rememberedContext,
                    'q' => $filters['q'] ?? null,
                    'page' => $filters['page'] ?? null,
                ], fn (mixed $value): bool => $value !== null && $value !== ''));
            }
        }

        $contextComplete = ! empty($filters['cycle'])
            && isset($filters['degree'])
            && ! empty($filters['group']);
        $contextValid = $contextComplete && $this->rosterContextExists($catalog, $filters);

        abort_if($contextComplete && ! $contextValid, 404);

        $enrollments = null;
        if ($contextValid) {
            $this->rememberRosterContext($request, $branch, $filters);

            $query = DB::table('student_roster')
                ->where('branch_code', $branch->code)
                ->where('enrollment_status', 'active')
                ->where('cycle_code', $filters['cycle'])
                ->where('degree_number', $filters['degree'])
                ->where('academic_group_code', $filters['group'])
                ->select([
                    'enrollment_code',
                    'student_code',
                    'dni',
                    'first_name',
                    'last_name',
                    'photo_path',
                    'student_is_active',
                    'roll_code',
                    'group_name',
                    'degree_number',
                    'cycle_name',
                    'shift_names',
                ]);

            $this->applyRosterSearch($query, (string) ($filters['q'] ?? ''));

            $enrollments = $query
                ->orderBy('full_name')
                ->paginate(20)
                ->withQueryString();
        }

        return Inertia::render('Students/Roster', [
            'enrollments' => [
                'data' => collect($enrollments?->items() ?? [])
                    ->map(fn (object $row): array => [
                        'code' => $row->enrollment_code,
                        'student_code' => $row->student_code,
                        'dni' => $row->dni,
                        'first_name' => $row->first_name,
                        'last_name' => $row->last_name,
                        'photo_url' => $row->photo_path
                            ? route('students.photo', $row->student_code)
                            : null,
                        'student_is_active' => (bool) $row->student_is_active,
                        'roll_code' => $row->roll_code,
                        'cycle_name' => $row->cycle_name,
                        'degree_label' => DegreeNumber::label((int) $row->degree_number),
                        'group_name' => $row->group_name,
                        'shift_names' => $row->shift_names,
                    ])
                    ->all(),
                'current_page' => $enrollments?->currentPage() ?? 1,
                'last_page' => $enrollments?->lastPage() ?? 1,
                'total' => $enrollments?->total() ?? 0,
            ],
            'filters' => [
                'q' => $filters['q'] ?? '',
                'cycle' => $filters['cycle'] ?? '',
                'degree' => isset($filters['degree']) ? (string) $filters['degree'] : '',
                'group' => $filters['group'] ?? '',
            ],
            'context_complete' => $contextValid,
            'catalog' => $catalog,
            'can_manage' => Gate::check('enrollments.manage'),
            'can_view_profiles' => Gate::check('students.view'),
        ]);
    }

    public function create(
        Request $request,
        Student $student,
        BranchContext $context,
    ): Response|RedirectResponse {
        $branch = $this->currentBranch($request, $context);
        if (! $branch) {
            return to_route('branches.index');
        }

        $existingEnrollment = $student->enrollments()
            ->with('cycle:code,branch_code,end_date')
            ->whereHas(
                'cycle',
                fn ($query) => $query
                    ->whereDate('end_date', '>=', $this->businessDate()),
            )
            ->latest()
            ->first();

        if ($existingEnrollment) {
            Inertia::flash(
                'info',
                'El alumno ya tiene una matrícula en un ciclo vigente. Edita esa matrícula.',
            );

            return $existingEnrollment->cycle?->branch_code === $branch->code
                ? to_route('enrollments.edit', $existingEnrollment)
                : $this->afterEnrollmentWrite($student);
        }

        return Inertia::render('Students/EnrollmentForm', [
            'student' => $this->studentSummary($student),
            'enrollment' => null,
            'options' => $this->enrollmentOptions($branch),
            'can_view_profile' => Gate::check('students.view'),
        ]);
    }

    public function store(
        EnrollmentRequest $request,
        Student $student,
        BranchContext $context,
        SaveEnrollment $saveEnrollment,
    ): RedirectResponse {
        $branch = $this->currentBranch($request, $context);
        if (! $branch) {
            return to_route('branches.index');
        }
        $saveEnrollment->handle(
            $branch,
            $student,
            null,
            $request->string('academic_group_code')->toString(),
            $request->collect('shift_codes')->all(),
            true,
            $request->input('observation'),
        );

        Inertia::flash('success', 'Matrícula registrada');

        return $this->afterEnrollmentWrite($student);
    }

    public function edit(
        Request $request,
        Enrollment $enrollment,
        BranchContext $context,
    ): Response|RedirectResponse {
        $branch = $this->currentBranch($request, $context);
        if (! $branch) {
            return to_route('branches.index');
        }

        $enrollment->load([
            'student:code,dni,first_name,last_name',
            'academicGroup.cycleDegree.cycle',
            'shifts:cycle_shifts.code,cycle_shifts.cycle_code,cycle_shifts.name',
        ]);
        abort_unless(
            $enrollment->academicGroup?->cycleDegree?->cycle?->branch_code === $branch->code,
            404,
        );

        if (
            $enrollment->academicGroup->cycleDegree->cycle->end_date
                ->isBefore($this->businessDate())
        ) {
            Inertia::flash('info', 'La matrícula pertenece a un ciclo finalizado y es de sólo lectura.');

            return $this->afterEnrollmentWrite($enrollment->student);
        }

        return Inertia::render('Students/EnrollmentForm', [
            'student' => $this->studentSummary($enrollment->student),
            'enrollment' => [
                'code' => $enrollment->code,
                'academic_group_code' => $enrollment->academic_group_code,
                'shift_codes' => $enrollment->shifts->pluck('code')->all(),
                'roll_code' => $enrollment->roll_code,
                'is_active' => $enrollment->is_active,
                'observation' => $enrollment->observation,
            ],
            'options' => $this->enrollmentOptions($branch, $enrollment),
            'can_view_profile' => Gate::check('students.view'),
        ]);
    }

    public function update(
        EnrollmentRequest $request,
        Enrollment $enrollment,
        BranchContext $context,
        SaveEnrollment $saveEnrollment,
    ): RedirectResponse {
        $branch = $this->currentBranch($request, $context);
        if (! $branch) {
            return to_route('branches.index');
        }
        $student = $enrollment->student()->firstOrFail();

        $saveEnrollment->handle(
            $branch,
            $student,
            $enrollment,
            $request->string('academic_group_code')->toString(),
            $request->collect('shift_codes')->all(),
            $request->boolean('is_active'),
            $request->input('observation'),
        );

        Inertia::flash('success', 'Matrícula actualizada');

        return $this->afterEnrollmentWrite($student);
    }

    public function updateState(
        EnrollmentStateRequest $request,
        Enrollment $enrollment,
        BranchContext $context,
        SaveEnrollment $saveEnrollment,
    ): RedirectResponse {
        $branch = $this->currentBranch($request, $context);
        if (! $branch) {
            return to_route('branches.index');
        }
        $enrollment->load('student', 'shifts:cycle_shifts.code');

        $saveEnrollment->handle(
            $branch,
            $enrollment->student,
            $enrollment,
            $enrollment->academic_group_code,
            $enrollment->shifts->pluck('code')->all(),
            $request->boolean('is_active'),
            $enrollment->observation,
        );

        Inertia::flash(
            'success',
            $request->boolean('is_active') ? 'Matrícula activada' : 'Matrícula desactivada',
        );

        return to_route('students.show', $enrollment->student);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyRosterSearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $normalized = mb_strtolower($search);
        $like = '%'.str_replace(['%', '_'], '', $normalized).'%';

        $query->where(function (Builder $builder) use ($search, $normalized, $like): void {
            $builder
                ->where('dni', $search)
                ->orWhere('roll_code', $search)
                ->orWhereRaw('lower(full_name) % ?', [$normalized])
                ->orWhereRaw('lower(full_name) LIKE ?', [$like]);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filterCatalog(Branch $branch): array
    {
        return AcademicCycle::query()
            ->active()
            ->where('branch_code', $branch->code)
            ->whereDate('end_date', '>=', $this->businessDate())
            ->with([
                'degrees' => fn ($query) => $query->orderBy('number'),
                'degrees.groups' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderBy('sort_order'),
            ])
            ->orderByDesc('start_date')
            ->get(['code', 'name', 'branch_code'])
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
            ])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $catalog
     * @param  array<string, mixed>  $filters
     */
    private function rosterContextExists(array $catalog, array $filters): bool
    {
        $cycle = collect($catalog)->firstWhere('code', $filters['cycle']);
        $degree = collect($cycle['degrees'] ?? [])->first(
            fn (array $item): bool => $item['number'] === (int) $filters['degree'],
        );

        return collect($degree['groups'] ?? [])->contains('code', $filters['group']);
    }

    /**
     * @param  list<array<string, mixed>>  $catalog
     * @return array{cycle: string, degree: int, group: string}|null
     */
    private function rememberedRosterContext(
        Request $request,
        Branch $branch,
        array $catalog,
    ): ?array {
        $sessionKey = self::ROSTER_CONTEXT_SESSION_KEY.'.'.$branch->code;
        $stored = $request->session()->get($sessionKey);

        if (! is_array($stored)) {
            return null;
        }

        $context = [
            'cycle' => (string) ($stored['cycle'] ?? ''),
            'degree' => (int) ($stored['degree'] ?? 0),
            'group' => (string) ($stored['group'] ?? ''),
        ];

        if (! $this->rosterContextExists($catalog, $context)) {
            $request->session()->forget($sessionKey);

            return null;
        }

        return $context;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function rememberRosterContext(
        Request $request,
        Branch $branch,
        array $filters,
    ): void {
        $request->session()->put(
            self::ROSTER_CONTEXT_SESSION_KEY.'.'.$branch->code,
            [
                'cycle' => (string) $filters['cycle'],
                'degree' => (int) $filters['degree'],
                'group' => (string) $filters['group'],
            ],
        );
    }

    /**
     * @return array{groups: list<array<string, mixed>>, shifts: list<array<string, mixed>>}
     */
    private function enrollmentOptions(
        Branch $branch,
        ?Enrollment $enrollment = null,
    ): array {
        $cycles = AcademicCycle::query()
            ->active()
            ->where('branch_code', $branch->code)
            ->whereDate('end_date', '>=', $this->businessDate())
            ->when(
                $enrollment,
                fn ($query) => $query->where('code', $enrollment->cycle_code),
            )
            ->with([
                'degrees' => fn ($query) => $query->orderBy('number'),
                'degrees.groups' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order'),
                'shifts' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order'),
            ])
            ->orderByDesc('start_date')
            ->get(['code', 'name', 'branch_code']);

        $groups = $cycles->flatMap(
            fn (AcademicCycle $cycle) => $cycle->degrees->flatMap(
                fn ($degree) => $degree->groups->map(fn ($group): array => [
                    'code' => $group->code,
                    'label' => "{$cycle->name} · ".DegreeNumber::label($degree->number)." · {$group->name}",
                    'cycle_code' => $cycle->code,
                ]),
            ),
        )->values();
        $shifts = $cycles->flatMap(
            fn (AcademicCycle $cycle) => $cycle->shifts->map(fn ($shift): array => [
                'code' => $shift->code,
                'label' => "{$cycle->name} · {$shift->name}",
                'cycle_code' => $cycle->code,
            ]),
        )->values();

        if ($enrollment) {
            $group = $enrollment->academicGroup;
            $degree = $group?->cycleDegree;
            $cycle = $degree?->cycle;

            if (
                $group
                && $degree
                && $cycle
                && ! $groups->contains('code', $group->code)
            ) {
                $groups->push([
                    'code' => $group->code,
                    'label' => "{$cycle->name} · ".DegreeNumber::label($degree->number)." · {$group->name} (histórica)",
                    'cycle_code' => $cycle->code,
                ]);
            }

            foreach ($enrollment->shifts as $shift) {
                if (! $shifts->contains('code', $shift->code)) {
                    $shifts->push([
                        'code' => $shift->code,
                        'label' => "{$cycle?->name} · {$shift->name} (histórico)",
                        'cycle_code' => $shift->cycle_code,
                    ]);
                }
            }
        }

        return [
            'groups' => $groups->all(),
            'shifts' => $shifts->all(),
        ];
    }

    /**
     * @return array{code: string, dni: string, first_name: string, last_name: string}
     */
    private function studentSummary(Student $student): array
    {
        return [
            'code' => $student->code,
            'dni' => $student->dni,
            'first_name' => $student->first_name,
            'last_name' => $student->last_name,
        ];
    }

    private function currentBranch(Request $request, BranchContext $context): ?Branch
    {
        /** @var AuthAccount $account */
        $account = $request->user();

        return $context->currentBranch($account);
    }

    private function afterEnrollmentWrite(Student $student): RedirectResponse
    {
        return Gate::check('students.view')
            ? to_route('students.show', $student)
            : to_route('enrollments.index');
    }

    private function businessDate(): string
    {
        return CarbonImmutable::now('America/Lima')->toDateString();
    }
}
