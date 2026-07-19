<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SaveRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\RoleRequest;
use App\Models\EmployeeRole;
use App\Models\Permission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(): Response
    {
        $roles = EmployeeRole::query()
            ->withCount(['permissionScopes', 'users'])
            ->orderBy('name')
            ->get(['code', 'name', 'description', 'is_active']);

        return Inertia::render('Admin/Roles/Index', [
            'roles' => $roles->map(fn (EmployeeRole $role): array => [
                'code' => $role->code,
                'name' => $role->name,
                'description' => $role->description,
                'is_active' => $role->is_active,
                'permissions_count' => $role->permission_scopes_count,
                'users_count' => $role->users_count,
            ])->all(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Roles/Form', [
            'role' => null,
            'permission_groups' => $this->permissionGroups(),
            'can_manage' => true,
        ]);
    }

    public function store(RoleRequest $request, SaveRole $saveRole): RedirectResponse
    {
        $saveRole->handle(
            null,
            [
                'name' => $request->string('name')->toString(),
                'description' => $request->input('description'),
                'is_active' => $request->boolean('is_active'),
            ],
            $request->collect('permission_codes')->all(),
        );

        Inertia::flash('success', 'Rol creado');

        return to_route('admin.roles.index');
    }

    public function show(EmployeeRole $role): Response
    {
        $role->load(['permissionScopes:code,name']);

        return Inertia::render('Admin/Roles/Form', [
            'role' => [
                'code' => $role->code,
                'name' => $role->name,
                'description' => $role->description,
                'is_active' => $role->is_active,
                'permission_codes' => $role->permissionScopes->pluck('code')->all(),
            ],
            'permission_groups' => $this->permissionGroups(),
            'can_manage' => Gate::check('roles.manage'),
        ]);
    }

    public function update(RoleRequest $request, EmployeeRole $role, SaveRole $saveRole): RedirectResponse
    {
        $saveRole->handle(
            $role,
            [
                'name' => $request->string('name')->toString(),
                'description' => $request->input('description'),
                'is_active' => $request->boolean('is_active'),
            ],
            $request->collect('permission_codes')->all(),
        );

        Inertia::flash('success', 'Rol actualizado');

        return to_route('admin.roles.show', $role);
    }

    /**
     * @return list<array{group: string, permissions: list<array{code: string, name: string, description: ?string}>}>
     */
    private function permissionGroups(): array
    {
        $permissions = Permission::query()
            ->orderBy('name')
            ->get(['code', 'name', 'description']);

        $groups = [];

        foreach ($permissions as $permission) {
            $group = explode('.', $permission->name, 2)[0] ?: 'otros';
            $groups[$group][] = [
                'code' => $permission->code,
                'name' => $permission->name,
                'description' => $permission->description,
            ];
        }

        ksort($groups);

        return collect($groups)
            ->map(fn (array $items, string $group): array => [
                'group' => $group,
                'permissions' => $items,
            ])
            ->values()
            ->all();
    }
}
