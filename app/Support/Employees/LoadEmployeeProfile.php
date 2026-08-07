<?php

namespace App\Support\Employees;

use App\Models\AuthAccount;
use App\Models\Branch;
use App\Models\EmployeeRole;
use App\Models\Permission;
use App\Models\User;
use App\Support\Branches\BranchContext;
use App\Support\EmployeeAttendance\EmployeeAttendanceQueries;
use App\Support\EmployeeAttendance\EmployeeSchedulePresenter;
use App\Support\EmployeeAttendance\EmployeeWeekday;
use App\Support\PrivateProfilePhoto;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * One profile payload for self (/profile) and admin (/admin/employees/{id}).
 * Tab-scoped data loading mirrors Coedula: only the active tab pays its query cost.
 */
final class LoadEmployeeProfile
{
    /** @var list<string> */
    private const TABS = ['general', 'attendance', 'schedules', 'access'];

    public function __construct(
        private readonly EmployeeAttendanceQueries $attendanceQueries,
        private readonly EmployeeSchedulePresenter $schedules,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(
        Request $request,
        User $subject,
        AuthAccount $actorAccount,
        BranchContext $branchContext,
        bool $isSelf,
    ): array {
        $actor = $actorAccount->user;
        abort_unless($actor, 403);

        $canManage = Gate::check('employees.manage');
        $canReadEmployee = Gate::check('employees.view');
        $canReadGeneral = $canReadEmployee || $isSelf;
        $canEditPhoto = $canManage || $isSelf;
        $canManageSchedules = Gate::check('employee_attendance.manage');
        // Coedula: self always sees own schedules/attendance; staff needs domain view.
        $canReadSchedules = $isSelf
            || $canManageSchedules
            || Gate::check('employee_attendance.view');
        $canReadAttendance = $isSelf || Gate::check('employee_attendance.view');

        $activeTab = $this->resolveTab(
            $request->query('tab'),
            $isSelf,
            $canReadEmployee,
            $canManage,
            $canReadAttendance,
            $canReadSchedules,
        );

        $currentBranch = $branchContext->currentBranch($actorAccount);
        $subject->loadMissing('employeeRole:code,name');

        if ($activeTab === 'general') {
            $subject->loadMissing([
                'branches:code,name',
                'authAccount:code,user_code,login,is_active,last_login_at',
            ]);
        }
        if ($activeTab === 'access') {
            $subject->loadMissing([
                'employeeRole.permissionScopes:code,name,description',
                'authAccount:code,user_code,login,is_active,last_login_at',
                'permissions:code,name,description',
            ]);
        }

        $schedules = [];
        if ($activeTab === 'schedules' && $canReadSchedules && $currentBranch) {
            $schedules = $this->schedules->forUserInBranch($subject, $currentBranch->code);
        }

        $attendance = null;
        if ($activeTab === 'attendance' && $canReadAttendance && $currentBranch) {
            $attendance = $this->attendanceBlock(
                $request,
                $subject,
                $currentBranch,
            );
        }

        $missingCard = [];
        if ($canReadGeneral) {
            if (! is_string($subject->dni) || preg_match('/^\d{8}$/', $subject->dni) !== 1) {
                $missingCard[] = 'DNI de ocho dígitos';
            }
            if (! is_string($subject->photo_path) || $subject->photo_path === '') {
                $missingCard[] = 'foto de perfil';
            }
        }

        $profileBranches = $subject->relationLoaded('branches')
            ? $subject->branches
            : collect($currentBranch ? [$currentBranch] : []);
        $accountLoaded = $subject->relationLoaded('authAccount')
            ? $subject->authAccount
            : null;
        $scopePermissions = $activeTab === 'access'
            ? $subject->employeeRole?->permissionScopes
                ?->map(fn (Permission $permission): array => [
                    'code' => $permission->code,
                    'name' => $permission->name,
                    'description' => $permission->description,
                ])
                ->values()
                ->all() ?? []
            : [];

        return [
            'is_self' => $isSelf,
            'active_tab' => $activeTab,
            'employee' => [
                'code' => $subject->code,
                'first_name' => $subject->first_name,
                'last_name' => $subject->last_name,
                'email' => $canReadGeneral ? $subject->email : null,
                'phone' => $canReadGeneral ? $subject->phone : null,
                'dni' => $subject->dni,
                'employee_role_code' => $subject->employee_role_code,
                'role_name' => $subject->employeeRole?->name,
                'is_active' => $subject->is_active,
                'is_super_admin' => $canReadGeneral && $subject->is_super_admin,
                'branch_codes' => $profileBranches->pluck('code')->all(),
                'branches' => $profileBranches
                    ->map(fn (Branch $branch): array => [
                        'code' => $branch->code,
                        'name' => $branch->name,
                    ])
                    ->values()
                    ->all(),
                'login' => $accountLoaded?->login,
                'access_active' => (bool) $accountLoaded?->is_active,
                'last_login_at' => $accountLoaded?->last_login_at?->toIso8601String(),
                'photo_url' => ($isSelf || $canReadEmployee || $canManageSchedules)
                    ? PrivateProfilePhoto::versionedUrl(
                        $subject->photo_path,
                        'admin.employees.photo',
                        ['employee' => $subject->code],
                    )
                    : null,
            ],
            'role_permission_scope' => $scopePermissions,
            'permission_codes' => $activeTab !== 'access' || $subject->is_super_admin
                ? []
                : $subject->permissions->pluck('code')->values()->all(),
            'roles' => $activeTab === 'general'
                ? ($canManage ? $this->roleOptions() : [[
                    'code' => $subject->employee_role_code,
                    'name' => $subject->employeeRole?->name ?? 'Sin rol',
                ]])
                : [],
            'branches' => $activeTab === 'general'
                ? ($canManage ? $this->branchOptions() : $profileBranches
                    ->map(fn (Branch $branch): array => [
                        'code' => $branch->code,
                        'name' => $branch->name,
                    ])->values()->all())
                : [],
            'can_manage' => $canManage,
            'can_read_general' => $canReadGeneral,
            'can_edit_photo' => $canEditPhoto,
            'can_manage_schedules' => $canManageSchedules,
            'can_read_schedules' => $canReadSchedules,
            'can_read_attendance' => $canReadAttendance,
            'current_branch' => $currentBranch
                ? ['code' => $currentBranch->code, 'name' => $currentBranch->name]
                : null,
            'schedules' => $schedules,
            'weekday_options' => $activeTab === 'schedules' ? EmployeeWeekday::options() : [],
            'attendance' => $attendance,
            'card_missing_requirements' => $missingCard,
            'profile_path' => $isSelf
                ? '/profile'
                : '/admin/employees/'.$subject->code,
        ];
    }

    private function resolveTab(
        mixed $requested,
        bool $isSelf,
        bool $canReadEmployee,
        bool $canManage,
        bool $canReadAttendance,
        bool $canReadSchedules,
    ): string {
        // Legacy deep links used tab=permissions; Acceso now owns both fieldsets.
        if ($requested === 'permissions') {
            $requested = 'access';
        }

        $tab = is_string($requested) && in_array($requested, self::TABS, true)
            ? $requested
            : 'general';

        $allowed = match ($tab) {
            'attendance' => $canReadAttendance,
            'schedules' => $canReadSchedules,
            'general' => $canReadEmployee || $isSelf,
            'access' => $canManage,
            default => false,
        };

        if ($allowed) {
            return $tab;
        }

        if ($canReadAttendance) {
            return 'attendance';
        }
        if ($canReadSchedules) {
            return 'schedules';
        }

        return 'general';
    }

    /**
     * @return array{
     *     filters: array{from: string, to: string},
     *     summary: array<string, int>,
     *     history: list<array<string, mixed>>,
     *     max_days: int
     * }
     */
    private function attendanceBlock(Request $request, User $subject, Branch $branch): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $timezone = (string) config('aeduca.business_timezone', 'America/Lima');
        $now = CarbonImmutable::now($timezone);
        $today = $now->startOfDay();
        $maxDays = max(1, (int) config('aeduca.attendance.history_max_days', 93));
        $defaultDays = max(1, (int) config('aeduca.attendance.history_default_days', 30));

        $to = isset($validated['to'])
            ? CarbonImmutable::parse($validated['to'], $timezone)->startOfDay()
            : $today;
        $from = isset($validated['from'])
            ? CarbonImmutable::parse($validated['from'], $timezone)->startOfDay()
            : $to->subDays($defaultDays - 1);

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        if ($to->gt($today)) {
            $to = $today;
        }
        if ($from->gt($to)) {
            $from = $to;
        }
        if (((int) $from->diffInDays($to)) + 1 > $maxDays) {
            $from = $to->subDays($maxDays - 1);
        }

        $rows = collect($this->attendanceQueries->history(
            $subject->code,
            $branch->code,
            $from->toDateString(),
            $to->toDateString(),
            $now,
        ));

        return [
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'summary' => [
                'expected' => $rows->count(),
                'present' => $rows->where('effective_state', 'present')->count(),
                'late' => $rows->where('effective_state', 'late')->count(),
                'absent' => $rows->where('effective_state', 'absent')->count(),
                'permission' => $rows->where('effective_state', 'permission')->count(),
                'justified' => $rows->where('effective_state', 'justified')->count(),
            ],
            'history' => $rows
                ->map(fn (object $row): array => $this->attendanceQueries->mapHistoryRow($row))
                ->all(),
            'max_days' => $maxDays,
        ];
    }

    /** @return list<array{code: string, name: string}> */
    private function roleOptions(): array
    {
        return EmployeeRole::query()
            ->active()
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(fn (EmployeeRole $role): array => [
                'code' => $role->code,
                'name' => $role->name,
            ])
            ->all();
    }

    /** @return list<array{code: string, name: string}> */
    private function branchOptions(): array
    {
        return Branch::query()
            ->active()
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(fn (Branch $branch): array => [
                'code' => $branch->code,
                'name' => $branch->name,
            ])
            ->all();
    }
}
