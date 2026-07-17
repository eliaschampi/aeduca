<?php

namespace App\Http\Controllers\Admin;

use App\Actions\CreateEmployee;
use App\Actions\SyncUserPermissionOverrides;
use App\Actions\UpdateEmployee;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeEmployeePasswordRequest;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\SyncUserPermissionOverridesRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\Branch;
use App\Models\EmployeeRole;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('employees.view');

        $employees = User::query()
            ->with(['employeeRole:code,name', 'authAccount:code,user_code,login,is_active'])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['code', 'first_name', 'last_name', 'email', 'employee_role_code', 'is_active']);

        return Inertia::render('Admin/Employees/Index', [
            'employees' => $employees->map(fn (User $employee): array => [
                'code' => $employee->code,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'email' => $employee->email,
                'role_name' => $employee->employeeRole?->name,
                'login' => $employee->authAccount?->login,
                'is_active' => $employee->is_active,
                'access_active' => (bool) $employee->authAccount?->is_active,
            ])->all(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('employees.manage');

        return Inertia::render('Admin/Employees/Create', $this->formOptions());
    }

    public function store(StoreEmployeeRequest $request, CreateEmployee $createEmployee): RedirectResponse
    {
        Gate::authorize('employees.manage');

        $employee = $createEmployee->handle(
            [
                'first_name' => $request->string('first_name')->toString(),
                'last_name' => $request->string('last_name')->toString(),
                'email' => $request->input('email'),
                'phone' => $request->input('phone'),
                'employee_role_code' => $request->string('employee_role_code')->toString(),
                'is_active' => $request->boolean('is_active'),
            ],
            $request->collect('branch_codes')->all(),
            $request->string('login')->toString(),
            $request->string('password')->toString(),
        );

        return to_route('admin.employees.show', $employee);
    }

    public function show(User $employee): Response
    {
        Gate::authorize('employees.view');

        $canManage = Gate::check('employees.manage');

        $employee->load([
            'employeeRole:code,name',
            'branches:code,name',
            'authAccount:code,user_code,login,is_active,last_login_at',
            'permissionOverrides:code,name,description',
        ]);

        return Inertia::render('Admin/Employees/Show', [
            'employee' => [
                'code' => $employee->code,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'email' => $employee->email,
                'phone' => $employee->phone,
                'employee_role_code' => $employee->employee_role_code,
                'role_name' => $employee->employeeRole?->name,
                'is_active' => $employee->is_active,
                'branch_codes' => $employee->branches->pluck('code')->all(),
                'branches' => $employee->branches
                    ->map(fn (Branch $branch): array => ['code' => $branch->code, 'name' => $branch->name])
                    ->all(),
                'login' => $employee->authAccount?->login,
                'access_active' => (bool) $employee->authAccount?->is_active,
                'last_login_at' => $employee->authAccount?->last_login_at?->toIso8601String(),
            ],
            // Catalog only when the viewer can add exceptions (keeps payload small for read-only).
            'permission_catalog' => $canManage
                ? Permission::query()
                    ->orderBy('name')
                    ->get(['code', 'name', 'description'])
                    ->map(fn (Permission $permission): array => [
                        'code' => $permission->code,
                        'name' => $permission->name,
                        'description' => $permission->description,
                    ])
                    ->all()
                : [],
            // Sparse rows; label included so viewers need no full catalog.
            'permission_overrides' => $employee->permissionOverrides
                ->map(fn (Permission $permission): array => [
                    'permission_code' => $permission->code,
                    'label' => filled($permission->description)
                        ? $permission->description
                        : $permission->name,
                    'is_allowed' => (bool) $permission->pivot->is_allowed,
                ])
                ->values()
                ->all(),
            ...$this->formOptions(),
        ]);
    }

    public function update(UpdateEmployeeRequest $request, User $employee, UpdateEmployee $updateEmployee): RedirectResponse
    {
        Gate::authorize('employees.manage');

        $updateEmployee->handle(
            $employee,
            [
                'first_name' => $request->string('first_name')->toString(),
                'last_name' => $request->string('last_name')->toString(),
                'email' => $request->input('email'),
                'phone' => $request->input('phone'),
                'employee_role_code' => $request->string('employee_role_code')->toString(),
                'is_active' => $request->boolean('is_active'),
            ],
            $request->collect('branch_codes')->all(),
        );

        return to_route('admin.employees.show', $employee);
    }

    public function changePassword(ChangeEmployeePasswordRequest $request, User $employee): RedirectResponse
    {
        Gate::authorize('employees.manage');

        $account = $employee->authAccount;

        if ($account) {
            // Model update so the `hashed` cast applies (query builder skips casts).
            $account->update([
                'password' => $request->string('password')->toString(),
            ]);
        }

        return to_route('admin.employees.show', $employee);
    }

    public function toggleAccess(User $employee): RedirectResponse
    {
        Gate::authorize('employees.manage');

        $account = $employee->authAccount;

        if ($account) {
            $account->update(['is_active' => ! $account->is_active]);
        }

        return to_route('admin.employees.show', $employee);
    }

    public function syncPermissionOverrides(
        SyncUserPermissionOverridesRequest $request,
        User $employee,
        SyncUserPermissionOverrides $syncUserPermissionOverrides,
    ): RedirectResponse {
        Gate::authorize('employees.manage');

        $syncUserPermissionOverrides->handle(
            $employee,
            $request->collect('overrides')
                ->map(fn (array $row): array => [
                    'permission_code' => $row['permission_code'],
                    'is_allowed' => (bool) $row['is_allowed'],
                ])
                ->values()
                ->all(),
        );

        return to_route('admin.employees.show', $employee);
    }

    /**
     * @return array{roles: list<array{code: string, name: string}>, branches: list<array{code: string, name: string}>}
     */
    private function formOptions(): array
    {
        return [
            'roles' => EmployeeRole::query()
                ->active()
                ->orderBy('name')
                ->get(['code', 'name'])
                ->map(fn (EmployeeRole $role): array => ['code' => $role->code, 'name' => $role->name])
                ->all(),
            'branches' => Branch::query()
                ->active()
                ->orderBy('name')
                ->get(['code', 'name'])
                ->map(fn (Branch $branch): array => ['code' => $branch->code, 'name' => $branch->name])
                ->all(),
        ];
    }
}
