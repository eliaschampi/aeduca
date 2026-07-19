<?php

namespace Tests\Feature;

use App\Models\EmployeeRole;
use App\Models\Permission;
use Tests\TestCase;

class UserPermissionGrantTest extends TestCase
{
    public function test_manager_can_set_direct_permission_grants_within_scope(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['employees.manage']);

        $target = $this->createEmployeeAccount()->user;
        $view = Permission::factory()->create(['name' => 'branches.view']);
        $manage = Permission::factory()->create(['name' => 'branches.manage']);
        $outOfScope = Permission::factory()->create(['name' => 'roles.view']);

        $target->employeeRole->permissionScopes()->attach([$view->code, $manage->code]);

        $this->actingAs($account)
            ->put(route('admin.employees.permissions', $target), [
                'permission_codes' => [$manage->code],
            ])
            ->assertRedirect(route('admin.employees.show', $target));

        // manage implies view
        $this->assertDatabaseHas('user_permissions', [
            'user_code' => $target->code,
            'permission_code' => $manage->code,
        ]);
        $this->assertDatabaseHas('user_permissions', [
            'user_code' => $target->code,
            'permission_code' => $view->code,
        ]);
        $this->assertDatabaseMissing('user_permissions', [
            'user_code' => $target->code,
            'permission_code' => $outOfScope->code,
        ]);
    }

    public function test_permission_outside_role_scope_is_rejected(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['employees.manage']);

        $target = $this->createEmployeeAccount()->user;
        $scoped = Permission::factory()->create(['name' => 'branches.view']);
        Permission::factory()->create(['name' => 'roles.view']);
        $outside = Permission::factory()->create(['name' => 'roles.manage']);
        $target->employeeRole->permissionScopes()->attach([$scoped->code]);

        $this->actingAs($account)
            ->from(route('admin.employees.show', $target))
            ->put(route('admin.employees.permissions', $target), [
                'permission_codes' => [$outside->code],
            ])
            ->assertSessionHasErrors('permission_codes');

        $this->assertDatabaseMissing('user_permissions', [
            'user_code' => $target->code,
            'permission_code' => $outside->code,
        ]);
    }

    public function test_empty_permission_list_clears_grants(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['employees.manage']);
        $target = $this->createEmployeeAccount()->user;
        $permission = Permission::factory()->create(['name' => 'roles.view']);
        $target->employeeRole->permissionScopes()->attach($permission->code);
        $target->permissions()->attach($permission->code);

        $this->actingAs($account)
            ->put(route('admin.employees.permissions', $target), [
                'permission_codes' => [],
            ])
            ->assertRedirect(route('admin.employees.show', $target));

        $this->assertDatabaseMissing('user_permissions', [
            'user_code' => $target->code,
            'permission_code' => $permission->code,
        ]);
    }

    public function test_sync_is_forbidden_without_manage_permission(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['employees.view']);
        $target = $this->createEmployeeAccount()->user;
        $permission = Permission::factory()->create(['name' => 'roles.manage']);

        $this->actingAs($account)
            ->put(route('admin.employees.permissions', $target), [
                'permission_codes' => [$permission->code],
            ])
            ->assertForbidden();
    }

    public function test_changing_role_removes_incompatible_direct_grants(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['employees.manage']);

        $targetAccount = $this->createEmployeeAccount();
        $target = $targetAccount->user;
        $keep = Permission::factory()->create(['name' => 'dashboard.view']);
        $drop = Permission::factory()->create(['name' => 'branches.view']);
        $target->employeeRole->permissionScopes()->attach([$keep->code, $drop->code]);
        $target->permissions()->attach([$keep->code, $drop->code]);

        $newRole = EmployeeRole::factory()->create(['is_active' => true]);
        $newRole->permissionScopes()->attach([$keep->code]);

        $branchCode = $target->branches()->firstOrFail()->code;

        $this->actingAs($account)
            ->put(route('admin.employees.update', $target), [
                'first_name' => $target->first_name,
                'last_name' => $target->last_name,
                'email' => $target->email,
                'phone' => $target->phone,
                'employee_role_code' => $newRole->code,
                'is_active' => true,
                'branch_codes' => [$branchCode],
            ])
            ->assertRedirect(route('admin.employees.show', $target));

        $this->assertDatabaseHas('user_permissions', [
            'user_code' => $target->code,
            'permission_code' => $keep->code,
        ]);
        $this->assertDatabaseMissing('user_permissions', [
            'user_code' => $target->code,
            'permission_code' => $drop->code,
        ]);
    }
}
