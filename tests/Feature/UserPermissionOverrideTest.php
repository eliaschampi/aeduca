<?php

namespace Tests\Feature;

use App\Models\Permission;
use Tests\TestCase;

class UserPermissionOverrideTest extends TestCase
{
    public function test_manager_can_set_allow_and_deny_overrides(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['employees.manage']);

        $target = $this->createEmployeeAccount()->user;
        $roleGrant = Permission::factory()->create(['name' => 'branches.view']);
        $roleOnly = Permission::factory()->create(['name' => 'branches.manage']);
        $extra = Permission::factory()->create(['name' => 'employees.view']);

        $target->employeeRole->permissions()->attach([$roleGrant->code, $roleOnly->code]);

        $this->actingAs($account)
            ->put(route('admin.employees.permission-overrides', $target), [
                'overrides' => [
                    ['permission_code' => $roleOnly->code, 'is_allowed' => false],
                    ['permission_code' => $extra->code, 'is_allowed' => true],
                ],
            ])
            ->assertRedirect(route('admin.employees.show', $target));

        $this->assertDatabaseHas('user_permissions', [
            'user_code' => $target->code,
            'permission_code' => $roleOnly->code,
            'is_allowed' => false,
        ]);
        $this->assertDatabaseHas('user_permissions', [
            'user_code' => $target->code,
            'permission_code' => $extra->code,
            'is_allowed' => true,
        ]);
        $this->assertDatabaseMissing('user_permissions', [
            'user_code' => $target->code,
            'permission_code' => $roleGrant->code,
        ]);
    }

    public function test_sync_with_empty_overrides_clears_all_exceptions(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['employees.manage']);
        $target = $this->createEmployeeAccount()->user;
        $permission = Permission::factory()->create(['name' => 'roles.view']);
        $target->permissionOverrides()->attach($permission->code, ['is_allowed' => true]);

        $this->actingAs($account)
            ->put(route('admin.employees.permission-overrides', $target), [
                'overrides' => [],
            ])
            ->assertRedirect(route('admin.employees.show', $target));

        $this->assertDatabaseMissing('user_permissions', [
            'user_code' => $target->code,
            'permission_code' => $permission->code,
        ]);
    }

    public function test_overrides_are_forbidden_without_manage_permission(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['employees.view']);
        $target = $this->createEmployeeAccount()->user;
        $permission = Permission::factory()->create(['name' => 'roles.manage']);

        $this->actingAs($account)
            ->put(route('admin.employees.permission-overrides', $target), [
                'overrides' => [
                    ['permission_code' => $permission->code, 'is_allowed' => true],
                ],
            ])
            ->assertForbidden();
    }
}
