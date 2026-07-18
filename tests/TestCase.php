<?php

namespace Tests;

use App\Models\AuthAccount;
use App\Models\Branch;
use App\Models\EmployeeRole;
use App\Models\Permission;
use App\Models\User;
use App\Support\Authorization\PermissionDependency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected string $validPassword;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->validPassword = Str::random(32);
    }

    /**
     * @param  array<string, mixed>  $accountAttributes
     * @param  array<string, mixed>  $userAttributes
     * @param  array<string, mixed>  $roleAttributes
     */
    protected function createEmployeeAccount(
        array $accountAttributes = [],
        array $userAttributes = [],
        array $roleAttributes = [],
        int $branchCount = 1,
    ): AuthAccount {
        $role = EmployeeRole::factory()->create($roleAttributes);
        $employee = User::factory()->create([
            ...$userAttributes,
            'employee_role_code' => $role->code,
        ]);
        $account = AuthAccount::factory()->create([
            ...$accountAttributes,
            'user_code' => $employee->code,
            'password' => $accountAttributes['password'] ?? $this->validPassword,
        ]);

        if ($branchCount > 0) {
            $branches = Branch::factory()->count($branchCount)->create();
            $employee->branches()->attach($branches);
        }

        return $account->load('user.employeeRole', 'user.branches');
    }

    protected function loginAndContinueSession(AuthAccount $account): string
    {
        $response = $this->post('/login', [
            'login' => $account->login,
            'password' => $this->validPassword,
        ]);
        $response->assertRedirect(route('branches.index'));

        $cookieName = (string) config('session.cookie');
        $sessionCookie = $response->getCookie($cookieName);
        $this->assertNotNull($sessionCookie);
        $this->withCookie($cookieName, $sessionCookie->getValue());

        return $this->app['session']->driver()->getId();
    }

    protected function grantDashboardPermission(AuthAccount $account): Permission
    {
        return $this->grantPermissions($account, ['dashboard.view'])[0];
    }

    /**
     * Put names in the role scope and as direct grants (effective access).
     * Expands manage → view.
     *
     * @param  list<string>  $names
     * @return list<Permission>
     */
    protected function grantPermissions(AuthAccount $account, array $names): array
    {
        $expanded = PermissionDependency::expandNames($names);

        $permissions = collect($expanded)->map(
            function (string $name): Permission {
                return Permission::query()->firstOrCreate(
                    ['name' => $name],
                    ['description' => null],
                );
            },
        );

        $codes = $permissions->pluck('code')->all();
        $account->user->employeeRole->permissionScopes()->syncWithoutDetaching($codes);
        $account->user->permissions()->syncWithoutDetaching($codes);

        return $permissions->all();
    }

    /**
     * Add permissions to a role's assignable scope only (no direct grants).
     *
     * @param  list<string>  $names
     * @return list<Permission>
     */
    protected function scopeRolePermissions(EmployeeRole $role, array $names): array
    {
        $expanded = PermissionDependency::expandNames($names);

        $permissions = collect($expanded)->map(
            function (string $name): Permission {
                return Permission::query()->firstOrCreate(
                    ['name' => $name],
                    ['description' => null],
                );
            },
        );

        $role->permissionScopes()->syncWithoutDetaching($permissions->pluck('code'));

        return $permissions->all();
    }
}
