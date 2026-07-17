<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BranchManagementTest extends TestCase
{
    public function test_index_lists_catalog_for_an_authorized_viewer(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['branches.view']);
        Branch::factory()->create(['name' => 'Sede Norte']);

        $this->actingAs($account)
            ->get(route('branches.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Branches/Index')
                ->where('can_view_catalog', true)
                ->has('catalog'));
    }

    public function test_index_hides_catalog_without_the_view_permission(): void
    {
        $account = $this->createEmployeeAccount();

        $this->actingAs($account)
            ->get(route('branches.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Branches/Index')
                ->where('can_view_catalog', false)
                ->where('catalog', []));
    }

    public function test_a_manager_can_create_a_branch_with_members(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['branches.manage']);
        $member = User::factory()->create([
            'employee_role_code' => $account->user->employee_role_code,
        ]);

        $this->actingAs($account)
            ->post(route('admin.branches.store'), [
                'name' => 'Sede Central',
                'is_active' => true,
                'user_codes' => [$member->code],
            ])
            ->assertRedirect(route('branches.index'));

        $branch = Branch::query()->where('name', 'Sede Central')->first();
        $this->assertNotNull($branch);
        $this->assertTrue($branch->is_active);
        $this->assertDatabaseHas('user_branches', [
            'user_code' => $member->code,
            'branch_code' => $branch->code,
        ]);
    }

    public function test_creating_a_branch_requires_at_least_one_member(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['branches.manage']);

        $this->actingAs($account)
            ->post(route('admin.branches.store'), [
                'name' => 'Sede Vacía',
                'is_active' => true,
                'user_codes' => [],
            ])
            ->assertSessionHasErrors('user_codes');

        $this->assertDatabaseMissing('branches', ['name' => 'Sede Vacía']);
    }

    public function test_creating_a_branch_is_forbidden_without_the_manage_permission(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['branches.view']);
        $member = $account->user;

        $this->actingAs($account)
            ->post(route('admin.branches.store'), [
                'name' => 'Sede Sur',
                'is_active' => true,
                'user_codes' => [$member->code],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('branches', ['name' => 'Sede Sur']);
    }

    public function test_a_manager_can_update_a_branch_and_sync_members(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['branches.manage']);
        $branch = Branch::factory()->create(['name' => 'Antiguo', 'is_active' => true]);
        $oldMember = User::factory()->create([
            'employee_role_code' => $account->user->employee_role_code,
        ]);
        $newMember = User::factory()->create([
            'employee_role_code' => $account->user->employee_role_code,
        ]);
        $branch->users()->attach($oldMember);

        $this->actingAs($account)
            ->put(route('admin.branches.update', $branch), [
                'name' => 'Renombrado',
                'is_active' => false,
                'user_codes' => [$newMember->code],
            ])
            ->assertRedirect(route('branches.index'));

        $this->assertDatabaseHas('branches', [
            'code' => $branch->code,
            'name' => 'Renombrado',
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('user_branches', [
            'user_code' => $newMember->code,
            'branch_code' => $branch->code,
        ]);
        $this->assertDatabaseMissing('user_branches', [
            'user_code' => $oldMember->code,
            'branch_code' => $branch->code,
        ]);
    }

    public function test_the_branch_name_is_required(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['branches.manage']);

        $this->actingAs($account)
            ->post(route('admin.branches.store'), [
                'name' => '',
                'is_active' => true,
                'user_codes' => [$account->user->code],
            ])
            ->assertSessionHasErrors('name');
    }

    public function test_the_branch_name_length_is_capped(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['branches.manage']);

        $this->actingAs($account)
            ->post(route('admin.branches.store'), [
                'name' => str_repeat('a', 121),
                'is_active' => true,
                'user_codes' => [$account->user->code],
            ])
            ->assertSessionHasErrors('name');
    }

    public function test_create_rejects_unknown_member_codes(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['branches.manage']);

        $this->actingAs($account)
            ->post(route('admin.branches.store'), [
                'name' => 'Sede X',
                'is_active' => true,
                'user_codes' => ['00000000-0000-4000-8000-000000000099'],
            ])
            ->assertSessionHasErrors('user_codes.0');

        $this->assertSame(0, DB::table('branches')->where('name', 'Sede X')->count());
    }
}
