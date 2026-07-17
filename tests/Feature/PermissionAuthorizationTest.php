<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Support\Authorization\PermissionResolver;
use Tests\TestCase;

class PermissionAuthorizationTest extends TestCase
{
    public function test_role_permission_is_granted(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantDashboardPermission($account);

        $resolver = app(PermissionResolver::class);

        $this->assertTrue($resolver->can($account, 'dashboard.view'));
        $this->assertSame(['dashboard.view'], $resolver->effectiveNames($account));
    }

    public function test_individual_override_can_grant_a_permission(): void
    {
        $account = $this->createEmployeeAccount();
        $permission = Permission::factory()->create(['name' => 'dashboard.view']);
        $account->user->permissionOverrides()->attach($permission, [
            'is_allowed' => true,
        ]);

        $this->assertTrue(
            app(PermissionResolver::class)->can($account, 'dashboard.view'),
        );
    }

    public function test_individual_denial_overrides_a_role_grant(): void
    {
        $account = $this->createEmployeeAccount();
        $permission = $this->grantDashboardPermission($account);
        $account->user->permissionOverrides()->attach($permission, [
            'is_allowed' => false,
        ]);

        $this->assertFalse(
            app(PermissionResolver::class)->can($account, 'dashboard.view'),
        );
    }

    public function test_superadministrator_receives_every_known_permission(): void
    {
        $account = $this->createEmployeeAccount(
            userAttributes: ['is_super_admin' => true],
        );
        $permission = Permission::factory()->create(['name' => 'dashboard.view']);
        $account->user->permissionOverrides()->attach($permission, [
            'is_allowed' => false,
        ]);

        $resolver = app(PermissionResolver::class);

        $this->assertTrue($resolver->can($account, 'dashboard.view'));
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
        $this->grantDashboardPermission($account);

        $this->actingAs($account)->get('/')->assertOk();
    }
}
