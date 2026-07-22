<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SaveCycle;
use App\Http\Controllers\Controller;
use App\Http\Requests\CycleRequest;
use App\Models\AcademicCycle;
use App\Models\AuthAccount;
use App\Models\Branch;
use App\Support\Academic\AcademicLevel;
use App\Support\Academic\CycleModality;
use App\Support\Branches\BranchContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CycleController extends Controller
{
    private const string BUSINESS_TIMEZONE = 'America/Lima';

    public function index(Request $request, BranchContext $context): Response|RedirectResponse
    {
        $branch = $this->currentBranch($request, $context);

        if (! $branch) {
            return redirect()->route('branches.index');
        }

        $today = CarbonImmutable::now(self::BUSINESS_TIMEZONE)->startOfDay();
        $cycles = $branch->cycles()
            ->withCount(['degrees', 'groups'])
            ->orderByDesc('start_date')
            ->get(['code', 'name', 'level', 'modality', 'start_date', 'end_date', 'is_active'])
            ->map(fn (AcademicCycle $cycle): array => [
                'code' => $cycle->code,
                'name' => $cycle->name,
                'level_label' => $cycle->level->label(),
                'modality_label' => $cycle->modality->label(),
                'start_date' => $cycle->start_date->toDateString(),
                'end_date' => $cycle->end_date->toDateString(),
                'is_active' => $cycle->is_active,
                'degrees_count' => $cycle->degrees_count,
                'groups_count' => $cycle->groups_count,
                'timeline' => $this->timeline($cycle, $today),
            ])
            ->all();

        return Inertia::render('Cycles/Index', [
            'cycles' => $cycles,
            'can_manage' => Gate::check('cycles.manage'),
        ]);
    }

    public function create(Request $request, BranchContext $context): Response|RedirectResponse
    {
        if (! $this->currentBranch($request, $context)) {
            return redirect()->route('branches.index');
        }

        return Inertia::render('Cycles/Form', [
            'cycle' => null,
            'level_options' => AcademicLevel::options(),
            'modality_options' => CycleModality::options(),
            'grade_numbers' => collect(AcademicLevel::cases())
                ->mapWithKeys(fn (AcademicLevel $level): array => [$level->value => $level->gradeNumbers()])
                ->all(),
            'can_manage' => Gate::check('cycles.manage'),
        ]);
    }

    public function store(CycleRequest $request, BranchContext $context, SaveCycle $saveCycle): RedirectResponse
    {
        $branch = $this->currentBranch($request, $context);

        if (! $branch) {
            return redirect()->route('branches.index');
        }

        $saveCycle->handle($branch, null, $this->cycleAttributes($request), $this->shifts($request), $this->degrees($request));

        Inertia::flash('success', 'Ciclo creado');

        return to_route('admin.cycles.index');
    }

    public function show(Request $request, BranchContext $context, AcademicCycle $cycle): Response|RedirectResponse
    {
        $branch = $this->currentBranch($request, $context);

        if (! $branch || $cycle->branch_code !== $branch->code) {
            abort(404);
        }

        $cycle->load([
            'degrees' => fn ($query) => $query->orderBy('number'),
            'degrees.groups' => fn ($query) => $query->orderBy('sort_order'),
            'shifts' => fn ($query) => $query->orderBy('sort_order'),
        ]);

        return Inertia::render('Cycles/Form', [
            'cycle' => [
                'code' => $cycle->code,
                'name' => $cycle->name,
                'level' => $cycle->level->value,
                'modality' => $cycle->modality->value,
                'start_date' => $cycle->start_date->toDateString(),
                'end_date' => $cycle->end_date->toDateString(),
                'is_active' => $cycle->is_active,
                'shifts' => $cycle->shifts->map(fn ($shift) => [
                    'code' => $shift->code,
                    'name' => $shift->name,
                    'entry_time' => substr($shift->entry_time, 0, 5),
                    'tolerance_minutes' => $shift->tolerance_minutes,
                ])->all(),
                'degrees' => $cycle->degrees->map(fn ($degree) => [
                    'number' => $degree->number,
                    'groups' => $degree->groups->map(fn ($group) => [
                        'code' => $group->code,
                        'name' => $group->name,
                    ])->all(),
                ])->all(),
            ],
            'level_options' => AcademicLevel::options(),
            'modality_options' => CycleModality::options(),
            'grade_numbers' => collect(AcademicLevel::cases())
                ->mapWithKeys(fn (AcademicLevel $level): array => [$level->value => $level->gradeNumbers()])
                ->all(),
            'can_manage' => Gate::check('cycles.manage'),
        ]);
    }

    public function update(CycleRequest $request, BranchContext $context, AcademicCycle $cycle, SaveCycle $saveCycle): RedirectResponse
    {
        $branch = $this->currentBranch($request, $context);

        if (! $branch || $cycle->branch_code !== $branch->code) {
            abort(404);
        }

        $saveCycle->handle($branch, $cycle, $this->cycleAttributes($request), $this->shifts($request), $this->degrees($request));

        Inertia::flash('success', 'Ciclo actualizado');

        return to_route('admin.cycles.index');
    }

    private function currentBranch(Request $request, BranchContext $context): ?Branch
    {
        /** @var AuthAccount $account */
        $account = $request->user();

        return $context->currentBranch($account);
    }

    /**
     * @return array{status: 'upcoming'|'active'|'completed', percentage: float, label: string}
     */
    private function timeline(AcademicCycle $cycle, CarbonImmutable $today): array
    {
        $start = CarbonImmutable::parse($cycle->start_date->toDateString(), self::BUSINESS_TIMEZONE);
        $end = CarbonImmutable::parse($cycle->end_date->toDateString(), self::BUSINESS_TIMEZONE);
        $totalDays = max((int) $start->diffInDays($end), 1);

        if ($today->lessThanOrEqualTo($start)) {
            $daysUntilStart = (int) $today->diffInDays($start);

            return [
                'status' => 'upcoming',
                'percentage' => 0.0,
                'label' => $daysUntilStart === 0
                    ? 'Empieza hoy'
                    : "Empieza en {$this->dayCount($daysUntilStart)}",
            ];
        }

        if ($today->greaterThanOrEqualTo($end)) {
            return [
                'status' => 'completed',
                'percentage' => 100.0,
                'label' => "Finalizado en {$this->dayCount($totalDays)}",
            ];
        }

        $passedDays = min((int) $start->diffInDays($today), $totalDays);

        return [
            'status' => 'active',
            'percentage' => round(($passedDays * 100) / $totalDays, 2),
            'label' => "Han transcurrido {$passedDays} de {$this->dayCount($totalDays)}",
        ];
    }

    private function dayCount(int $days): string
    {
        return "{$days} día".($days === 1 ? '' : 's');
    }

    /**
     * @return array{name: string, level: string, modality: string, start_date: string, end_date: string, is_active: bool}
     */
    private function cycleAttributes(CycleRequest $request): array
    {
        return [
            'name' => trim($request->string('name')->toString()),
            'level' => $request->string('level')->toString(),
            'modality' => $request->string('modality')->toString(),
            'start_date' => $request->string('start_date')->toString(),
            'end_date' => $request->string('end_date')->toString(),
            'is_active' => $request->boolean('is_active'),
        ];
    }

    /**
     * @return list<array{code?: string|null, name: string, entry_time: string, tolerance_minutes: int}>
     */
    private function shifts(CycleRequest $request): array
    {
        return collect($request->input('shifts', []))
            ->map(fn (array $shift): array => [
                'code' => $shift['code'] ?? null,
                'name' => trim((string) ($shift['name'] ?? '')),
                'entry_time' => (string) ($shift['entry_time'] ?? ''),
                'tolerance_minutes' => (int) ($shift['tolerance_minutes'] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{number: int, groups: list<array{code?: string|null, name: string}>}>
     */
    private function degrees(CycleRequest $request): array
    {
        return collect($request->input('degrees', []))
            ->map(fn (array $degree): array => [
                'number' => (int) $degree['number'],
                'groups' => collect($degree['groups'] ?? [])
                    ->map(fn (array $group): array => [
                        'code' => $group['code'] ?? null,
                        'name' => trim((string) ($group['name'] ?? '')),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
