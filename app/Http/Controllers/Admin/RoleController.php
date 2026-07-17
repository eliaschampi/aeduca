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
        Gate::authorize('roles.view');

        $roles = EmployeeRole::query()
            ->withCount(['permissions', 'users'])
            ->orderBy('name')
            ->get(['code', 'name', 'description', 'is_active']);

        return Inertia::render('Admin/Roles/Index', [
            'roles' => $roles->map(fn (EmployeeRole $role): array => [
                'code' => $role->code,
                'name' => $role->name,
                'description' => $role->description,
                'is_active' => $role->is_active,
                'permissions_count' => $role->permissions_count,
                'users_count' => $role->users_count,
            ])->all(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('roles.manage');

        return Inertia::render('Admin/Roles/Form', [
            'role' => null,
            'permission_groups' => $this->permissionGroups(),
            'can_manage' => true,
        ]);
    }

    public function store(RoleRequest $request, SaveRole $saveRole): RedirectResponse
    {
        Gate::authorize('roles.manage');

        $role = $saveRole->handle(
            null,
            [
                'name' => $request->string('name')->toString(),
                'description' => $request->input('description'),
                'is_active' => $request->boolean('is_active'),
            ],
            $request->collect('permission_codes')->all(),
        );

        return to_route('admin.roles.show', $role);
    }

    public function show(EmployeeRole $role): Response
    {
        Gate::authorize('roles.view');

        $role->load(['permissions:code,name']);

        $granted = $role->permissions->pluck('code')->all();

        return Inertia::render('Admin/Roles/Form', [
            'role' => [
                'code' => $role->code,
                'name' => $role->name,
                'description' => $role->description,
                'is_active' => $role->is_active,
                'permission_codes' => $granted,
            ],
            'permission_groups' => $this->permissionGroups(),
            'can_manage' => Gate::check('roles.manage'),
        ]);
    }

    public function update(RoleRequest $request, EmployeeRole $role, SaveRole $saveRole): RedirectResponse
    {
        Gate::authorize('roles.manage');

        $saveRole->handle(
            $role,
            [
                'name' => $request->string('name')->toString(),
                'description' => $request->input('description'),
                'is_active' => $request->boolean('is_active'),
            ],
            $request->collect('permission_codes')->all(),
        );

        return to_route('admin.roles.show', $role);
    }

    /**
     * Permissions grouped by domain prefix for a calm matrix UI.
     *
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
