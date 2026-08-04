<?php

namespace App\Http\Controllers\Admin;

use App\Actions\CreateEmployee;
use App\Actions\SyncUserPermissions;
use App\Actions\UpdateEmployee;
use App\Actions\UpdateEmployeePhoto;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeEmployeePasswordRequest;
use App\Http\Requests\ProfilePhotoRequest;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\SyncUserPermissionsRequest;
use App\Http\Requests\UpdateEmployeeAccessRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\AuthAccount;
use App\Models\Branch;
use App\Models\EmployeeRole;
use App\Models\Permission;
use App\Models\User;
use App\Support\PrivateProfilePhoto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EmployeeController extends Controller
{
    public function index(): Response
    {
        $employees = User::query()
            ->with(['employeeRole:code,name', 'authAccount:code,user_code,login,is_active'])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['code', 'first_name', 'last_name', 'email', 'employee_role_code', 'is_active', 'is_super_admin', 'photo_path']);

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
                'photo_url' => $this->photoUrl($employee),
            ])->all(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Employees/Create', $this->formOptions());
    }

    public function store(StoreEmployeeRequest $request, CreateEmployee $createEmployee): RedirectResponse
    {
        $createEmployee->handle(
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

        Inertia::flash('success', 'Usuario creado');

        return to_route('admin.employees.index');
    }

    public function show(Request $request, User $employee): Response
    {
        $canManage = Gate::check('employees.manage');
        $canEditPhoto = $this->canWritePhoto($request, $employee);

        $employee->load([
            'employeeRole.permissionScopes:code,name,description',
            'branches:code,name',
            'authAccount:code,user_code,login,is_active,last_login_at',
            'permissions:code,name,description',
        ]);

        $scopePermissions = $employee->employeeRole?->permissionScopes
            ->map(fn (Permission $permission): array => [
                'code' => $permission->code,
                'name' => $permission->name,
                'description' => $permission->description,
            ])
            ->values()
            ->all() ?? [];

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
                'is_super_admin' => $employee->is_super_admin,
                'branch_codes' => $employee->branches->pluck('code')->all(),
                'branches' => $employee->branches
                    ->map(fn (Branch $branch): array => ['code' => $branch->code, 'name' => $branch->name])
                    ->all(),
                'login' => $employee->authAccount?->login,
                'access_active' => (bool) $employee->authAccount?->is_active,
                'last_login_at' => $employee->authAccount?->last_login_at?->toIso8601String(),
                'photo_url' => $this->photoUrl($employee),
            ],
            // Role scope = assignable boundary (not automatic access).
            'role_permission_scope' => $scopePermissions,
            // Direct grants only (empty for super_admin UI messaging).
            'permission_codes' => $employee->is_super_admin
                ? []
                : $employee->permissions->pluck('code')->values()->all(),
            ...$this->formOptions(),
            'can_manage' => $canManage,
            'can_edit_photo' => $canEditPhoto,
        ]);
    }

    public function updatePhoto(
        ProfilePhotoRequest $request,
        User $employee,
        UpdateEmployeePhoto $updatePhoto,
    ): RedirectResponse {
        abort_unless($this->canWritePhoto($request, $employee), 403);

        $photo = $request->file('photo');
        abort_unless($photo, 422);

        $updatePhoto->handle($employee, $photo);

        Inertia::flash('success', 'Foto actualizada');

        return back();
    }

    public function photo(
        Request $request,
        User $employee,
        PrivateProfilePhoto $photos,
    ): BinaryFileResponse {
        abort_unless($this->canReadPhoto($request, $employee), 403);
        abort_unless($photos->exists($employee->photo_path), 404);

        return response()
            ->file($photos->absolutePath((string) $employee->photo_path))
            ->setPrivate()
            ->setMaxAge(300);
    }

    public function update(UpdateEmployeeRequest $request, User $employee, UpdateEmployee $updateEmployee): RedirectResponse
    {
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

        Inertia::flash('success', 'Usuario actualizado');

        return to_route('admin.employees.show', $employee);
    }

    public function changePassword(ChangeEmployeePasswordRequest $request, User $employee): RedirectResponse
    {
        $employee->authAccount()->firstOrFail()->update([
            'password' => $request->string('password')->toString(),
        ]);

        Inertia::flash('success', 'Contraseña actualizada');

        return to_route('admin.employees.show', $employee);
    }

    public function updateAccess(UpdateEmployeeAccessRequest $request, User $employee): RedirectResponse
    {
        $isActive = $request->boolean('is_active');

        $employee->authAccount()->firstOrFail()->update(['is_active' => $isActive]);

        Inertia::flash(
            'success',
            $isActive ? 'Acceso habilitado' : 'Acceso deshabilitado',
        );

        return to_route('admin.employees.show', $employee);
    }

    public function syncPermissions(
        SyncUserPermissionsRequest $request,
        User $employee,
        SyncUserPermissions $syncUserPermissions,
    ): RedirectResponse {
        if ($employee->is_super_admin) {
            return to_route('admin.employees.show', $employee);
        }

        $syncUserPermissions->handle(
            $employee,
            $request->collect('permission_codes')->all(),
        );

        Inertia::flash('success', 'Permisos actualizados');

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

    private function photoUrl(User $employee): ?string
    {
        return PrivateProfilePhoto::versionedUrl(
            $employee->photo_path,
            'admin.employees.photo',
            ['employee' => $employee],
        );
    }

    private function canReadPhoto(Request $request, User $employee): bool
    {
        if (Gate::check('employees.view')) {
            return true;
        }

        return $this->isSelf($request, $employee);
    }

    private function canWritePhoto(Request $request, User $employee): bool
    {
        if (Gate::check('employees.manage')) {
            return true;
        }

        return $this->isSelf($request, $employee);
    }

    private function isSelf(Request $request, User $employee): bool
    {
        /** @var AuthAccount|null $account */
        $account = $request->user();

        return is_string($account?->user_code)
            && $account->user_code === $employee->code;
    }
}
