<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\EmployeeRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmployeeManagementTest extends TestCase
{
    public function test_index_lists_users_for_an_authorized_viewer(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['employees.view']);

        $this->actingAs($account)
            ->get(route('admin.employees.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Employees/Index')
                ->has('employees'));
    }

    public function test_index_is_forbidden_without_the_view_permission(): void
    {
        $account = $this->createEmployeeAccount();

        $this->actingAs($account)
            ->get(route('admin.employees.index'))
            ->assertForbidden();
    }

    public function test_a_manager_can_create_a_user_with_profile_branches_and_credentials(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['employees.manage']);
        $role = EmployeeRole::factory()->create(['is_active' => true]);
        $branch = Branch::factory()->create(['is_active' => true]);

        $this->actingAs($account)
            ->post(route('admin.employees.store'), [
                'first_name' => 'María',
                'last_name' => 'Quispe',
                'email' => 'maria@example.com',
                'phone' => '999111222',
                'employee_role_code' => $role->code,
                'is_active' => true,
                'branch_codes' => [$branch->code],
                'login' => 'mquispe',
                'password' => 'secret-password',
            ])
            ->assertRedirect();

        $employee = User::query()->where('first_name', 'María')->where('last_name', 'Quispe')->first();
        $this->assertNotNull($employee);
        $this->assertDatabaseHas('user_branches', [
            'user_code' => $employee->code,
            'branch_code' => $branch->code,
        ]);
        $this->assertDatabaseHas('auth_accounts', [
            'user_code' => $employee->code,
            'login' => 'mquispe',
            'is_active' => true,
        ]);
    }

    public function test_creating_a_user_is_forbidden_before_validation_without_manage_permission(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['employees.view']);

        $this->actingAs($account)
            ->post(route('admin.employees.store'), [])
            ->assertForbidden();
    }

    public function test_create_requires_at_least_one_branch(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['employees.manage']);
        $role = EmployeeRole::factory()->create();

        $this->actingAs($account)
            ->post(route('admin.employees.store'), [
                'first_name' => 'Luis',
                'last_name' => 'Perez',
                'employee_role_code' => $role->code,
                'is_active' => true,
                'branch_codes' => [],
                'login' => 'lperez',
                'password' => 'secret-password',
            ])
            ->assertSessionHasErrors('branch_codes');
    }

    public function test_create_rejects_duplicate_login(): void
    {
        $account = $this->createEmployeeAccount([
            'login' => 'existing',
        ]);
        $this->grantPermissions($account, ['employees.manage']);
        $role = EmployeeRole::factory()->create();
        $branch = Branch::factory()->create();

        $this->actingAs($account)
            ->post(route('admin.employees.store'), [
                'first_name' => 'Otro',
                'last_name' => 'Usuario',
                'employee_role_code' => $role->code,
                'is_active' => true,
                'branch_codes' => [$branch->code],
                'login' => 'existing',
                'password' => 'secret-password',
            ])
            ->assertSessionHasErrors('login');
    }

    public function test_a_manager_can_update_profile_role_and_branches(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['employees.manage']);
        $target = $this->createEmployeeAccount()->user;
        $newRole = EmployeeRole::factory()->create(['is_active' => true]);
        $newBranch = Branch::factory()->create(['is_active' => true]);

        $this->actingAs($account)
            ->put(route('admin.employees.update', $target), [
                'first_name' => 'Nombre',
                'last_name' => 'Actualizado',
                'email' => null,
                'phone' => null,
                'employee_role_code' => $newRole->code,
                'is_active' => true,
                'branch_codes' => [$newBranch->code],
            ])
            ->assertRedirect(route('admin.employees.show', $target));

        $this->assertDatabaseHas('users', [
            'code' => $target->code,
            'first_name' => 'Nombre',
            'last_name' => 'Actualizado',
            'employee_role_code' => $newRole->code,
        ]);
        $this->assertDatabaseHas('user_branches', [
            'user_code' => $target->code,
            'branch_code' => $newBranch->code,
        ]);
        $this->assertSame(1, DB::table('user_branches')->where('user_code', $target->code)->count());
    }

    public function test_a_manager_can_change_password_and_set_access_idempotently(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['employees.manage']);
        $targetAccount = $this->createEmployeeAccount();
        $target = $targetAccount->user;

        $this->actingAs($account)
            ->put(route('admin.employees.password', $target), [
                'password' => 'new-secret-pass',
            ])
            ->assertRedirect(route('admin.employees.show', $target));

        $this->assertTrue(
            Hash::check('new-secret-pass', $targetAccount->fresh()->password),
        );

        $this->actingAs($account)
            ->put(route('admin.employees.access', $target), ['is_active' => false])
            ->assertRedirect(route('admin.employees.show', $target));

        $this->actingAs($account)
            ->put(route('admin.employees.access', $target), ['is_active' => false])
            ->assertRedirect(route('admin.employees.show', $target));

        $this->assertFalse((bool) $targetAccount->fresh()->is_active);
    }

    public function test_access_update_requires_an_explicit_state(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['employees.manage']);
        $targetAccount = $this->createEmployeeAccount();

        $this->actingAs($account)
            ->put(route('admin.employees.access', $targetAccount->user), [])
            ->assertSessionHasErrors('is_active');

        $this->assertTrue((bool) $targetAccount->fresh()->is_active);
    }
}
