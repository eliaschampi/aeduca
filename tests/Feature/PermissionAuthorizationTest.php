<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Support\Authorization\PermissionResolver;
use Tests\TestCase;

class PermissionAuthorizationTest extends TestCase
{
    public function test_role_scope_alone_does_not_grant_access(): void
    {
        $account = $this->createEmployeeAccount();
        $this->scopeRolePermissions($account->user->employeeRole, ['dashboard.view']);

        $resolver = app(PermissionResolver::class);

        $this->assertFalse($resolver->can($account, 'dashboard.view'));
        $this->assertSame([], $resolver->effectiveNames($account));
    }

    public function test_direct_user_permission_within_scope_grants_access(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['dashboard.view']);

        $resolver = app(PermissionResolver::class);

        $this->assertTrue($resolver->can($account, 'dashboard.view'));
        $this->assertSame(['dashboard.view'], $resolver->effectiveNames($account));
    }

    public function test_direct_permission_outside_role_scope_is_not_effective(): void
    {
        $account = $this->createEmployeeAccount();
        $permission = Permission::factory()->create(['name' => 'dashboard.view']);
        // Direct grant without putting it in the role scope.
        $account->user->permissions()->attach($permission);

        $this->assertFalse(
            app(PermissionResolver::class)->can($account, 'dashboard.view'),
        );
    }

    public function test_superadministrator_receives_every_known_permission(): void
    {
        $account = $this->createEmployeeAccount(
            userAttributes: ['is_super_admin' => true],
        );
        Permission::factory()->create(['name' => 'dashboard.view']);
        Permission::factory()->create(['name' => 'branches.view']);

        $resolver = app(PermissionResolver::class);

        $this->assertTrue($resolver->can($account, 'dashboard.view'));
        $this->assertTrue($resolver->can($account, 'branches.view'));
        $this->assertFalse($resolver->can($account, 'unknown.permission'));
    }

    public function test_home_rejects_employee_without_dashboard_permission(): void
    {
        $account = $this->createEmployeeAccount();

        $this->actingAs($account)->get('/')->assertForbidden();
    }

    public function test_home_allows_employee_with_dashboard_permission(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['dashboard.view']);

        $this->actingAs($account)->get('/')->assertOk();
    }

    public function test_manage_grant_includes_view_for_navigation(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['employees.manage']);

        $resolver = app(PermissionResolver::class);

        $this->assertTrue($resolver->can($account, 'employees.manage'));
        $this->assertTrue($resolver->can($account, 'employees.view'));

        $this->actingAs($account)
            ->get(route('admin.employees.index'))
            ->assertOk();
        $this->actingAs($account)
            ->get(route('admin.employees.create'))
            ->assertOk();
    }

    public function test_request_cache_is_scoped_to_the_account(): void
    {
        $allowedAccount = $this->createEmployeeAccount();
        $deniedAccount = $this->createEmployeeAccount();
        $this->grantPermissions($allowedAccount, ['dashboard.view']);

        $resolver = app(PermissionResolver::class);

        $this->assertSame(['dashboard.view'], $resolver->effectiveNames($allowedAccount));
        $this->assertSame([], $resolver->effectiveNames($deniedAccount));
    }
}
