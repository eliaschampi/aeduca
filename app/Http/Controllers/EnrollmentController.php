<?php

namespace App\Http\Controllers;

use App\Actions\SaveEnrollment;
use App\Http\Requests\EnrollmentRequest;
use App\Models\AcademicCycle;
use App\Models\AuthAccount;
use App\Models\Branch;
use App\Models\Enrollment;
use App\Models\Student;
use App\Support\Branches\BranchContext;
use App\Support\Business\BusinessCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class EnrollmentController extends Controller
{
    public function create(
        Request $request,
        BranchContext $context,
        Student $student,
    ): Response|RedirectResponse {
        $account = $this->account($request);
        $currentBranch = $context->currentBranch($account);

        if (! $currentBranch) {
            return redirect()->route('branches.index');
        }

        return $this->form(
            $student,
            null,
            $context->authorizedBranches($account),
            $currentBranch->code,
        );
    }

    public function store(
        EnrollmentRequest $request,
        BranchContext $context,
        Student $student,
        SaveEnrollment $saveEnrollment,
    ): RedirectResponse {
        $account = $this->account($request);

        if (! $context->currentBranch($account)) {
            return redirect()->route('branches.index');
        }

        $saveEnrollment->handle(
            $student,
            null,
            $context->authorizedBranches($account)->pluck('code')->all(),
            $this->attributes($request),
            $request->array('shift_codes'),
            $this->obligations($request),
        );

        Inertia::flash('success', 'Matrícula registrada');

        return to_route('students.show', $student);
    }

    public function edit(
        Request $request,
        BranchContext $context,
        Student $student,
        Enrollment $enrollment,
    ): Response|RedirectResponse {
        $account = $this->account($request);
        $currentBranch = $context->currentBranch($account);

        if (! $currentBranch) {
            return redirect()->route('branches.index');
        }

        $authorizedBranches = $context->authorizedBranches($account);
        $this->ensureAccessible($student, $enrollment, $authorizedBranches->pluck('code')->all());

        return $this->form($student, $enrollment, $authorizedBranches, $currentBranch->code);
    }

    public function update(
        EnrollmentRequest $request,
        BranchContext $context,
        Student $student,
        Enrollment $enrollment,
        SaveEnrollment $saveEnrollment,
    ): RedirectResponse {
        $account = $this->account($request);

        if (! $context->currentBranch($account)) {
            return redirect()->route('branches.index');
        }

        $authorizedBranchCodes = $context->authorizedBranches($account)->pluck('code')->all();
        $this->ensureAccessible($student, $enrollment, $authorizedBranchCodes);

        $saveEnrollment->handle(
            $student,
            $enrollment,
            $authorizedBranchCodes,
            $this->attributes($request),
            $request->array('shift_codes'),
            $this->obligations($request),
        );

        Inertia::flash('success', 'Matrícula actualizada');

        return to_route('students.show', $student);
    }

    /**
     * @param  Collection<int, Branch>  $authorizedBranches
     */
    private function form(
        Student $student,
        ?Enrollment $enrollment,
        Collection $authorizedBranches,
        string $currentBranchCode,
    ): Response {
        $today = BusinessCalendar::today()->toDateString();
        $existingCycleCode = null;
        $existingGroupCode = null;
        $existingShiftCodes = [];

        if ($enrollment !== null) {
            $enrollment->load([
                'academicGroup.cycleDegree',
                'shifts',
                'obligations' => fn ($query) => $query->orderBy('due_date')->orderBy('code'),
            ]);
            $existingCycleCode = $enrollment->academicGroup->cycleDegree->cycle_code;
            $existingGroupCode = $enrollment->academic_group_code;
            $existingShiftCodes = $enrollment->shifts->pluck('code')->all();
        }

        $cycles = AcademicCycle::query()
            ->whereIn('branch_code', $authorizedBranches->pluck('code'))
            ->where(function ($query) use ($today, $existingCycleCode): void {
                $query->where(function ($eligible) use ($today): void {
                    $eligible
                        ->where('is_active', true)
                        ->whereDate('end_date', '>=', $today);
                });

                if ($existingCycleCode !== null) {
                    $query->orWhere('code', $existingCycleCode);
                }
            })
            ->with([
                'branch:code,name',
                'degrees' => fn ($query) => $query->orderBy('number'),
                'degrees.groups' => function ($query) use ($existingGroupCode): void {
                    $query
                        ->where(function ($available) use ($existingGroupCode): void {
                            $available->where('is_active', true);

                            if ($existingGroupCode !== null) {
                                $available->orWhere('code', $existingGroupCode);
                            }
                        })
                        ->orderBy('sort_order')
                        ->orderBy('name');
                },
                'shifts' => function ($query) use ($existingShiftCodes): void {
                    $query
                        ->where(function ($available) use ($existingShiftCodes): void {
                            $available->where('is_active', true);

                            if ($existingShiftCodes !== []) {
                                $available->orWhereIn('code', $existingShiftCodes);
                            }
                        })
                        ->orderBy('sort_order')
                        ->orderBy('name');
                },
            ])
            ->orderByDesc('start_date')
            ->orderBy('name')
            ->get()
            ->map(fn (AcademicCycle $cycle): array => [
                'code' => $cycle->code,
                'branch_code' => $cycle->branch_code,
                'label' => "{$cycle->name} · {$cycle->branch->name}",
                'groups' => $cycle->degrees
                    ->flatMap(fn ($degree) => $degree->groups->map(fn ($group): array => [
                        'value' => $group->code,
                        'label' => "{$cycle->level->gradeLabel($degree->number)} · Sección {$group->name}",
                    ]))
                    ->values()
                    ->all(),
                'shifts' => $cycle->shifts
                    ->map(fn ($shift): array => [
                        'value' => $shift->code,
                        'label' => $shift->name,
                    ])
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $cycle): bool => $cycle['groups'] !== [] && $cycle['shifts'] !== [])
            ->values();

        $defaultCycleCode = $existingCycleCode
            ?? $cycles->firstWhere('branch_code', $currentBranchCode)['code']
            ?? $cycles->first()['code']
            ?? null;

        return Inertia::render('Enrollments/Form', [
            'student' => [
                'code' => $student->code,
                'dni' => $student->dni,
                'full_name' => "{$student->first_name} {$student->last_name}",
            ],
            'enrollment' => $enrollment === null ? null : [
                'code' => $enrollment->code,
                'cycle_code' => $existingCycleCode,
                'academic_group_code' => $enrollment->academic_group_code,
                'shift_codes' => $existingShiftCodes,
                'is_active' => $enrollment->is_active,
                'observation' => $enrollment->observation,
                'obligations' => $enrollment->obligations
                    ->map(fn ($obligation): array => [
                        'code' => $obligation->code,
                        'concept' => $obligation->concept,
                        'amount' => $obligation->amount,
                        'due_date' => $obligation->due_date->toDateString(),
                    ])
                    ->all(),
            ],
            'cycles' => $cycles
                ->map(function (array $cycle): array {
                    unset($cycle['branch_code']);

                    return $cycle;
                })
                ->all(),
            'default_cycle_code' => $defaultCycleCode,
            'business_date' => $today,
        ]);
    }

    /**
     * @param  list<string>  $authorizedBranchCodes
     */
    private function ensureAccessible(
        Student $student,
        Enrollment $enrollment,
        array $authorizedBranchCodes,
    ): void {
        $enrollment->loadMissing('academicGroup.cycleDegree.cycle');

        if ($enrollment->student_code !== $student->code
            || ! in_array(
                $enrollment->academicGroup->cycleDegree->cycle->branch_code,
                $authorizedBranchCodes,
                true,
            )) {
            abort(404);
        }
    }

    /**
     * @return array{academic_group_code: string, is_active: bool, observation: ?string}
     */
    private function attributes(EnrollmentRequest $request): array
    {
        return [
            'academic_group_code' => $request->string('academic_group_code')->toString(),
            'is_active' => $request->boolean('is_active'),
            'observation' => $request->input('observation'),
        ];
    }

    /**
     * @return list<array{code?: string|null, concept: string, amount: int|float|string, due_date: string}>
     */
    private function obligations(EnrollmentRequest $request): array
    {
        return collect($request->input('obligations', []))
            ->map(fn (array $obligation): array => [
                'code' => $obligation['code'] ?? null,
                'concept' => (string) $obligation['concept'],
                'amount' => $obligation['amount'],
                'due_date' => (string) $obligation['due_date'],
            ])
            ->values()
            ->all();
    }

    private function account(Request $request): AuthAccount
    {
        /** @var AuthAccount $account */
        $account = $request->user();

        return $account;
    }
}
