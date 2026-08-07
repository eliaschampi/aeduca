<?php

namespace App\Http\Controllers\Admin;

use App\Actions\CreateEmployee;
use App\Actions\SaveEmployeeSchedule;
use App\Actions\SyncUserPermissions;
use App\Actions\UpdateEmployee;
use App\Actions\UpdateEmployeePhoto;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeEmployeePasswordRequest;
use App\Http\Requests\EmployeeScheduleRequest;
use App\Http\Requests\ProfilePhotoRequest;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\SyncUserPermissionsRequest;
use App\Http\Requests\UpdateEmployeeAccessRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\AuthAccount;
use App\Models\Branch;
use App\Models\EmployeeRole;
use App\Models\EmployeeSchedule;
use App\Models\User;
use App\Support\Branches\BranchContext;
use App\Support\Employees\LoadEmployeeProfile;
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
            ->get(['code', 'first_name', 'last_name', 'email', 'dni', 'employee_role_code', 'is_active', 'is_super_admin', 'photo_path']);

        return Inertia::render('Admin/Employees/Index', [
            'employees' => $employees->map(fn (User $employee): array => [
                'code' => $employee->code,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'email' => $employee->email,
                'dni' => $employee->dni,
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
                'dni' => $request->input('dni'),
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

    public function show(
        Request $request,
        User $employee,
        BranchContext $branchContext,
        LoadEmployeeProfile $loadProfile,
    ): Response {
        /** @var AuthAccount $account */
        $account = $request->user();
        $actor = $account->user;
        $isSelf = $actor !== null && $actor->code === $employee->code;

        return Inertia::render(
            'Employees/Profile',
            $loadProfile->handle(
                $request,
                $employee,
                $account,
                $branchContext,
                isSelf: $isSelf,
            ),
        );
    }

    public function storeSchedule(
        EmployeeScheduleRequest $request,
        User $employee,
        BranchContext $branchContext,
        SaveEmployeeSchedule $save,
    ): RedirectResponse {
        abort_unless(Gate::check('employee_attendance.manage'), 403);
        $branch = $this->requireCurrentBranch($request, $branchContext);
        abort_unless(
            $employee->branches()->where('branches.code', $branch->code)->exists(),
            404,
        );

        /** @var AuthAccount $account */
        $account = $request->user();
        $actor = $account->user;
        abort_unless($actor, 403);

        $validated = $request->validated();
        $isEdit = filled($validated['schedule_code'] ?? null);
        $save->save($actor, [
            'user_code' => $employee->code,
            'branch_code' => $branch->code,
            ...$validated,
        ]);

        Inertia::flash('success', $isEdit ? 'Horario actualizado' : 'Horario agregado');

        return back();
    }

    public function destroySchedule(
        Request $request,
        User $employee,
        EmployeeSchedule $schedule,
        BranchContext $branchContext,
        SaveEmployeeSchedule $save,
    ): RedirectResponse {
        abort_unless(Gate::check('employee_attendance.manage'), 403);
        $branch = $this->requireCurrentBranch($request, $branchContext);
        abort_unless(
            $schedule->user_code === $employee->code
            && $schedule->branch_code === $branch->code,
            404,
        );

        /** @var AuthAccount $account */
        $account = $request->user();
        $actor = $account->user;
        abort_unless($actor, 403);

        $save->delete($actor, $schedule);
        Inertia::flash('success', 'Horario eliminado');

        return back();
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
                'dni' => $request->input('dni'),
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
        if (Gate::check('employees.view') || Gate::check('employee_attendance.manage')) {
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

    private function requireCurrentBranch(Request $request, BranchContext $branchContext): Branch
    {
        /** @var AuthAccount $account */
        $account = $request->user();
        $branch = $branchContext->currentBranch($account);
        abort_unless($branch, 403);

        return $branch;
    }
}
