<?php

namespace Tests\Feature;

use App\Models\EmployeeRole;
use App\Models\Permission;
use App\Models\User;
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

    public function test_a_manager_can_create_a_role_with_permission_scope(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['roles.manage']);
        $permission = Permission::factory()->create(['name' => 'branches.view']);

        $this->actingAs($account)
            ->post(route('admin.roles.store'), [
                'name' => 'Operaciones',
                'description' => 'Alcance operativo',
                'is_active' => true,
                'permission_codes' => [$permission->code],
            ])
            ->assertRedirect();

        $role = EmployeeRole::query()->where('name', 'Operaciones')->first();
        $this->assertNotNull($role);
        $this->assertDatabaseHas('employee_role_permission_scopes', [
            'employee_role_code' => $role->code,
            'permission_code' => $permission->code,
        ]);
    }

    public function test_creating_a_role_is_forbidden_before_validation_without_manage_permission(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['roles.view']);

        $this->actingAs($account)
            ->post(route('admin.roles.store'), [])
            ->assertForbidden();
    }

    public function test_a_manager_can_update_role_permission_scope(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['roles.manage']);
        $role = EmployeeRole::factory()->create(['name' => 'Base']);
        $keep = Permission::factory()->create(['name' => 'dashboard.view']);
        $drop = Permission::factory()->create(['name' => 'branches.view']);
        $add = Permission::factory()->create(['name' => 'employees.view']);
        $role->permissionScopes()->attach([$keep->code, $drop->code]);

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
        $this->assertDatabaseHas('employee_role_permission_scopes', [
            'employee_role_code' => $role->code,
            'permission_code' => $keep->code,
        ]);
        $this->assertDatabaseHas('employee_role_permission_scopes', [
            'employee_role_code' => $role->code,
            'permission_code' => $add->code,
        ]);
        $this->assertDatabaseMissing('employee_role_permission_scopes', [
            'employee_role_code' => $role->code,
            'permission_code' => $drop->code,
        ]);
    }

    public function test_expanding_role_scope_does_not_auto_grant_users(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['roles.manage']);

        $role = EmployeeRole::factory()->create();
        $employee = User::factory()->create(['employee_role_code' => $role->code]);
        $permission = Permission::factory()->create(['name' => 'branches.view']);

        $this->actingAs($account)
            ->put(route('admin.roles.update', $role), [
                'name' => $role->name,
                'description' => null,
                'is_active' => true,
                'permission_codes' => [$permission->code],
            ])
            ->assertRedirect(route('admin.roles.show', $role));

        $this->assertDatabaseHas('employee_role_permission_scopes', [
            'employee_role_code' => $role->code,
            'permission_code' => $permission->code,
        ]);
        $this->assertDatabaseMissing('user_permissions', [
            'user_code' => $employee->code,
            'permission_code' => $permission->code,
        ]);
    }

    public function test_reducing_role_scope_prunes_incompatible_user_grants(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['roles.manage']);

        $role = EmployeeRole::factory()->create();
        $keep = Permission::factory()->create(['name' => 'dashboard.view']);
        $drop = Permission::factory()->create(['name' => 'branches.view']);
        $role->permissionScopes()->attach([$keep->code, $drop->code]);

        $employee = User::factory()->create(['employee_role_code' => $role->code]);
        $employee->permissions()->attach([$keep->code, $drop->code]);

        $this->actingAs($account)
            ->put(route('admin.roles.update', $role), [
                'name' => $role->name,
                'description' => null,
                'is_active' => true,
                'permission_codes' => [$keep->code],
            ])
            ->assertRedirect(route('admin.roles.show', $role));

        $this->assertDatabaseHas('user_permissions', [
            'user_code' => $employee->code,
            'permission_code' => $keep->code,
        ]);
        $this->assertDatabaseMissing('user_permissions', [
            'user_code' => $employee->code,
            'permission_code' => $drop->code,
        ]);
    }

    public function test_saving_manage_scope_persists_matching_view(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['roles.manage']);
        $view = Permission::factory()->create(['name' => 'students.view']);
        $manage = Permission::factory()->create(['name' => 'students.manage']);

        $this->actingAs($account)
            ->post(route('admin.roles.store'), [
                'name' => 'RRHH',
                'description' => null,
                'is_active' => true,
                'permission_codes' => [$manage->code],
            ])
            ->assertRedirect();

        $role = EmployeeRole::query()->where('name', 'RRHH')->first();
        $this->assertNotNull($role);
        $this->assertDatabaseHas('employee_role_permission_scopes', [
            'employee_role_code' => $role->code,
            'permission_code' => $manage->code,
        ]);
        $this->assertDatabaseHas('employee_role_permission_scopes', [
            'employee_role_code' => $role->code,
            'permission_code' => $view->code,
        ]);
    }

    public function test_role_name_is_required(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['roles.manage']);

        $this->actingAs($account)
            ->post(route('admin.roles.store'), [
                'name' => '',
                'description' => null,
                'is_active' => true,
                'permission_codes' => [],
            ])
            ->assertSessionHasErrors('name');
    }
}
