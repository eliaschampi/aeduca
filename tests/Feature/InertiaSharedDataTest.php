<?php

namespace Tests\Feature;

use App\Models\Branch;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InertiaSharedDataTest extends TestCase
{
    public function test_shared_props_contain_the_authenticated_context_and_effective_permissions(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantDashboardPermission($account);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home')
                ->where('auth.employee.first_name', $account->user->first_name)
                ->where('auth.employee.last_name', $account->user->last_name)
                ->where('auth.employee.role_name', $account->user->employeeRole->name)
                ->where('auth.current_branch.code', $branch->code)
                ->where('auth.current_branch.name', $branch->name)
                ->where('auth.permissions', ['dashboard.view'])
                ->has('auth.branches', 1));
    }

    public function test_shared_props_include_only_active_authorized_branches(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantDashboardPermission($account);
        $inactiveBranch = Branch::factory()->create(['is_active' => false]);
        Branch::factory()->create();
        $account->user->branches()->attach($inactiveBranch);

        $this->actingAs($account)
            ->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->has('auth.branches', 1)
                ->where(
                    'auth.branches.0.code',
                    $account->user->branches->firstWhere('is_active', true)->code,
                )
                ->missing('auth.branches.1')
                ->where(
                    'auth.current_branch.code',
                    $account->user->branches->firstWhere('is_active', true)->code,
                ));
    }

    public function test_guest_login_page_has_a_null_authentication_context(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login')
                ->where('auth', null));
    }
}
