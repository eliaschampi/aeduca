<?php

namespace Tests;

use App\Models\AuthAccount;
use App\Models\Branch;
use App\Models\EmployeeRole;
use App\Models\Permission;
use App\Models\User;
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
        $permission = Permission::factory()->create(['name' => 'dashboard.view']);
        $account->user->employeeRole->permissions()->attach($permission);

        return $permission;
    }
}
