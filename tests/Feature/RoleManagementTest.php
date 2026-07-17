<?php

namespace Tests\Feature;

use App\Models\EmployeeRole;
use App\Models\Permission;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    public function test_index_lists_roles_for_an_authorized_viewer(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['roles.view']);
        EmployeeRole::factory()->create(['name' => 'Secretaría']);

        $this->actingAs($account)
            ->get(route('admin.roles.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Roles/Index')
                ->has('roles'));
    }

    public function test_index_is_forbidden_without_view_permission(): void
    {
        $account = $this->createEmployeeAccount();

        $this->actingAs($account)
            ->get(route('admin.roles.index'))
            ->assertForbidden();
    }

    public function test_a_manager_can_create_a_role_with_permissions(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['roles.manage']);
        $permission = Permission::factory()->create(['name' => 'branches.view']);

        $this->actingAs($account)
            ->post(route('admin.roles.store'), [
                'name' => 'Operaciones',
                'description' => 'Acceso operativo',
                'is_active' => true,
                'permission_codes' => [$permission->code],
            ])
            ->assertRedirect();

        $role = EmployeeRole::query()->where('name', 'Operaciones')->first();
        $this->assertNotNull($role);
        $this->assertDatabaseHas('role_permissions', [
            'employee_role_code' => $role->code,
            'permission_code' => $permission->code,
        ]);
    }

    public function test_creating_a_role_is_forbidden_without_manage_permission(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['roles.view']);

        $this->actingAs($account)
            ->post(route('admin.roles.store'), [
                'name' => 'Bloqueado',
                'description' => null,
                'is_active' => true,
                'permission_codes' => [],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('employee_roles', ['name' => 'Bloqueado']);
    }

    public function test_a_manager_can_update_role_permissions(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['roles.manage']);
        $role = EmployeeRole::factory()->create(['name' => 'Base']);
        $keep = Permission::factory()->create(['name' => 'dashboard.view']);
        $drop = Permission::factory()->create(['name' => 'branches.view']);
        $add = Permission::factory()->create(['name' => 'employees.view']);
        $role->permissions()->attach([$keep->code, $drop->code]);

        $this->actingAs($account)
            ->put(route('admin.roles.update', $role), [
                'name' => 'Base actualizado',
                'description' => 'Ajustado',
                'is_active' => true,
                'permission_codes' => [$keep->code, $add->code],
            ])
            ->assertRedirect(route('admin.roles.show', $role));

        $this->assertDatabaseHas('employee_roles', [
            'code' => $role->code,
            'name' => 'Base actualizado',
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'employee_role_code' => $role->code,
            'permission_code' => $keep->code,
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'employee_role_code' => $role->code,
            'permission_code' => $add->code,
        ]);
        $this->assertDatabaseMissing('role_permissions', [
            'employee_role_code' => $role->code,
            'permission_code' => $drop->code,
        ]);
    }

    public function test_role_name_is_required(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['roles.manage']);

        $this->actingAs($account)
            ->post(route('admin.roles.store'), [
                'name' => '',
                'is_active' => true,
                'permission_codes' => [],
            ])
            ->assertSessionHasErrors('name');
    }
}
