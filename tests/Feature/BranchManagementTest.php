<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Collection;
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

    public function test_a_manager_can_create_a_branch_without_employees(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['branches.manage']);

        $this->actingAs($account)
            ->post(route('admin.branches.store'), [
                'name' => 'Sede Central',
                'is_active' => true,
            ])
            ->assertRedirect(route('branches.index'));

        $branch = Branch::query()->where('name', 'Sede Central')->first();
        $this->assertNotNull($branch);
        $this->assertTrue($branch->is_active);
        $this->assertSame(0, DB::table('user_branches')->where('branch_code', $branch->code)->count());
    }

    public function test_creating_a_branch_is_forbidden_before_validation_without_manage_permission(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['branches.view']);

        $this->actingAs($account)
            ->post(route('admin.branches.store'), [])
            ->assertForbidden();
    }

    public function test_a_manager_can_update_branch_attributes_without_membership_sync(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['branches.manage']);
        $branch = Branch::factory()->create(['name' => 'Antiguo', 'is_active' => true]);
        $member = User::factory()->create([
            'employee_role_code' => $account->user->employee_role_code,
        ]);
        $branch->users()->attach($member);

        $this->actingAs($account)
            ->put(route('admin.branches.update', $branch), [
                'name' => 'Renombrado',
                'is_active' => false,
            ])
            ->assertRedirect(route('branches.index'));

        $this->assertDatabaseHas('branches', [
            'code' => $branch->code,
            'name' => 'Renombrado',
            'is_active' => false,
        ]);
        // Membership untouched by branch edit.
        $this->assertDatabaseHas('user_branches', [
            'user_code' => $member->code,
            'branch_code' => $branch->code,
        ]);
    }

    public function test_branch_edit_rejects_user_codes_payload(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['branches.manage']);
        $branch = Branch::factory()->create(['name' => 'Sede X']);

        $this->actingAs($account)
            ->put(route('admin.branches.update', $branch), [
                'name' => 'Sede Y',
                'is_active' => true,
                'user_codes' => [$account->user->code],
            ])
            ->assertRedirect(route('branches.index'));

        // Extra user_codes are ignored; membership not owned here.
        $this->assertDatabaseMissing('user_branches', [
            'user_code' => $account->user->code,
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
            ])
            ->assertSessionHasErrors('name');
    }

    public function test_manager_with_manage_can_view_catalog_results(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['branches.manage']);
        Branch::factory()->create(['name' => 'Visible por manage→view']);

        $this->actingAs($account)
            ->get(route('branches.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_view_catalog', true)
                ->where('can_manage', true)
                ->where(
                    'catalog',
                    fn (Collection $catalog): bool => $catalog->contains(
                        fn (array $branch): bool => $branch['name'] === 'Visible por manage→view',
                    ),
                ));
    }
}
