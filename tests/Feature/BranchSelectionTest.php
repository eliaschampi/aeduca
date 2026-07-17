<?php

namespace Tests\Feature;

use App\Models\Branch;
use Tests\TestCase;

class BranchSelectionTest extends TestCase
{
    public function test_multiple_branches_require_an_explicit_selection_after_login(): void
    {
        $account = $this->createEmployeeAccount(branchCount: 2);

        $this->post('/login', [
            'login' => $account->login,
            'password' => $this->validPassword,
        ])
            ->assertRedirect(route('branches.index'))
            ->assertSessionMissing('current_branch_code');
    }

    public function test_employee_can_select_an_authorized_active_branch(): void
    {
        $account = $this->createEmployeeAccount(branchCount: 2);
        $branch = $account->user->branches->last();

        $this->actingAs($account)
            ->withHeader('Referer', 'https://outside.example/redirect-target')
            ->put('/current-branch', ['branch_code' => $branch->code])
            ->assertRedirect(route('branches.index'))
            ->assertSessionHas('current_branch_code', $branch->code);
    }

    public function test_unauthorized_branch_is_rejected_without_changing_context(): void
    {
        $account = $this->createEmployeeAccount();
        $authorizedBranch = $account->user->branches->sole();
        $unauthorizedBranch = Branch::factory()->create();

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $authorizedBranch->code])
            ->put('/current-branch', ['branch_code' => $unauthorizedBranch->code])
            ->assertForbidden()
            ->assertSessionHas('current_branch_code', $authorizedBranch->code);
    }

    public function test_inactive_branch_is_rejected(): void
    {
        $account = $this->createEmployeeAccount();
        $inactiveBranch = Branch::factory()->create(['is_active' => false]);
        $account->user->branches()->attach($inactiveBranch);

        $this->actingAs($account)
            ->put('/current-branch', ['branch_code' => $inactiveBranch->code])
            ->assertForbidden();
    }

    public function test_invalid_branch_identifier_is_rejected_by_validation(): void
    {
        $account = $this->createEmployeeAccount();

        $this->actingAs($account)
            ->put('/current-branch', ['branch_code' => 'not-a-uuid'])
            ->assertSessionHasErrors('branch_code');
    }

    public function test_session_is_rejected_when_the_last_active_branch_is_removed(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantDashboardPermission($account);
        $authenticatedSessionId = $this->loginAndContinueSession($account);

        $branch->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();

        $this->get('/')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors([
                'login' => 'Tu cuenta no tiene una sede activa asignada. Contacta a un administrador.',
            ])
            ->assertSessionMissing('current_branch_code');

        $this->assertDatabaseMissing('sessions', ['id' => $authenticatedSessionId]);
        $this->assertGuest();
    }

    public function test_only_remaining_active_branch_replaces_a_stale_context(): void
    {
        $account = $this->createEmployeeAccount(branchCount: 2);
        $staleBranch = $account->user->branches->first();
        $remainingBranch = $account->user->branches->last();
        $this->grantDashboardPermission($account);
        $staleBranch->update(['is_active' => false]);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $staleBranch->code])
            ->get('/')
            ->assertOk()
            ->assertSessionHas('current_branch_code', $remainingBranch->code);
    }
}
