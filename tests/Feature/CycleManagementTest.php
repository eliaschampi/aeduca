<?php

namespace Tests\Feature;

use App\Actions\SaveCycle;
use App\Models\AcademicCycle;
use App\Models\Branch;
use App\Models\CycleDegree;
use App\Models\CycleShift;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CycleManagementTest extends TestCase
{
    public function test_unauthorized_user_cannot_view_cycles(): void
    {
        $account = $this->createEmployeeAccount();

        $this->actingAs($account)
            ->get(route('admin.cycles.index'))
            ->assertForbidden();
    }

    public function test_unauthorized_user_cannot_manage_cycles(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['cycles.view']);

        $this->actingAs($account)
            ->post(route('admin.cycles.store'), [])
            ->assertForbidden();
    }

    public function test_manage_permission_includes_view(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['cycles.manage']);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('admin.cycles.index'))
            ->assertOk();
    }

    public function test_view_only_user_receives_read_only_cycle_capabilities(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['cycles.view']);
        $cycle = AcademicCycle::factory()->create(['branch_code' => $branch->code]);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('admin.cycles.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Cycles/Index')
                ->where('can_manage', false));

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('admin.cycles.show', $cycle))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Cycles/Form')
                ->where('can_manage', false));
    }

    public function test_management_user_receives_cycle_management_capabilities(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['cycles.manage']);
        $cycle = AcademicCycle::factory()->create(['branch_code' => $branch->code]);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('admin.cycles.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can_manage', true));

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('admin.cycles.show', $cycle))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can_manage', true));
    }

    public function test_index_shows_only_current_branch_cycles(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['cycles.view']);

        AcademicCycle::factory()->create(['branch_code' => $branch->code, 'name' => 'Ciclo Propio']);

        $otherBranch = Branch::factory()->create();
        AcademicCycle::factory()->create(['branch_code' => $otherBranch->code, 'name' => 'Ciclo Ajeno']);

        $response = $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('admin.cycles.index'));

        $response->assertOk();
        $cycles = $response->inertiaProps('cycles');
        $this->assertCount(1, $cycles);
        $this->assertSame('Ciclo Propio', $cycles[0]['name']);
    }

    public function test_index_exposes_lima_date_progress_for_each_cycle_state(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-11 12:00:00', 'America/Lima'));

        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['cycles.view']);

        AcademicCycle::factory()->create([
            'branch_code' => $branch->code,
            'name' => 'Próximo',
            'start_date' => '2026-07-20',
            'end_date' => '2026-07-30',
        ]);
        AcademicCycle::factory()->create([
            'branch_code' => $branch->code,
            'name' => 'Vigente',
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-21',
        ]);
        AcademicCycle::factory()->create([
            'branch_code' => $branch->code,
            'name' => 'Finalizado',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-11',
        ]);

        $response = $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('admin.cycles.index'))
            ->assertOk();

        $cycles = collect($response->inertiaProps('cycles'))->keyBy('name');

        $this->assertSame([
            'status' => 'upcoming',
            'percentage' => 0,
            'label' => 'Empieza en 9 días',
        ], $cycles['Próximo']['timeline']);
        $this->assertSame([
            'status' => 'active',
            'percentage' => 50,
            'label' => 'Han transcurrido 10 de 20 días',
        ], $cycles['Vigente']['timeline']);
        $this->assertSame([
            'status' => 'completed',
            'percentage' => 100,
            'label' => 'Finalizado en 10 días',
        ], $cycles['Finalizado']['timeline']);
    }

    public function test_user_cannot_access_cycle_from_another_branch(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['cycles.view']);

        $otherBranch = Branch::factory()->create();
        $foreignCycle = AcademicCycle::factory()->create(['branch_code' => $otherBranch->code]);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('admin.cycles.show', $foreignCycle))
            ->assertNotFound();
    }

    public function test_cycle_may_cross_calendar_years(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['cycles.manage']);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->post(route('admin.cycles.store'), $this->validPayload([
                'start_date' => '2026-11-01',
                'end_date' => '2027-03-15',
            ]))
            ->assertRedirect(route('admin.cycles.index'));

        $cycle = AcademicCycle::query()->where('branch_code', $branch->code)->first();
        $this->assertNotNull($cycle);
        $this->assertSame('2026-11-01', $cycle->start_date->toDateString());
        $this->assertSame('2027-03-15', $cycle->end_date->toDateString());
    }

    public function test_invalid_date_order_is_rejected(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['cycles.manage']);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->post(route('admin.cycles.store'), $this->validPayload([
                'start_date' => '2026-06-01',
                'end_date' => '2026-03-01',
            ]))
            ->assertSessionHasErrors('end_date');
    }

    public function test_cycle_name_does_not_restrict_sixth_grade(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['cycles.manage']);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->post(route('admin.cycles.store'), $this->validPayload([
                'name' => 'Secundaria',
                'degrees' => [['number' => 6, 'groups' => [['name' => 'A']]]],
            ]))
            ->assertRedirect(route('admin.cycles.index'));

        $this->assertDatabaseHas('cycle_degrees', ['number' => 6]);
    }

    public function test_grade_above_supported_range_is_rejected(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['cycles.manage']);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->post(route('admin.cycles.store'), $this->validPayload([
                'degrees' => [['number' => 7, 'groups' => [['name' => 'A']]]],
            ]))
            ->assertSessionHasErrors('degrees.0.number');
    }

    public function test_duplicate_grade_in_same_cycle_is_rejected(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['cycles.manage']);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->post(route('admin.cycles.store'), $this->validPayload([
                'degrees' => [
                    ['number' => 3, 'groups' => [['name' => 'A']]],
                    ['number' => 3, 'groups' => [['name' => 'B']]],
                ],
            ]))
            ->assertSessionHasErrors('degrees.1.number');
    }

    public function test_duplicate_group_in_same_degree_is_rejected(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['cycles.manage']);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->post(route('admin.cycles.store'), $this->validPayload([
                'degrees' => [
                    ['number' => 1, 'groups' => [['name' => 'A'], ['name' => 'a ']]],
                ],
            ]))
            ->assertSessionHasErrors('degrees.0.groups.1.name');
    }

    public function test_same_group_name_allowed_in_different_degrees(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['cycles.manage']);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->post(route('admin.cycles.store'), $this->validPayload([
                'degrees' => [
                    ['number' => 1, 'groups' => [['name' => 'A']]],
                    ['number' => 2, 'groups' => [['name' => 'A']]],
                ],
            ]))
            ->assertRedirect(route('admin.cycles.index'));

        $this->assertSame(2, CycleDegree::query()->count());
    }

    public function test_more_than_two_shifts_is_rejected(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['cycles.manage']);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->post(route('admin.cycles.store'), $this->validPayload([
                'shifts' => [
                    ['name' => 'Mañana', 'entry_time' => '07:00', 'tolerance_minutes' => 30],
                    ['name' => 'Tarde', 'entry_time' => '13:00', 'tolerance_minutes' => 30],
                    ['name' => 'Noche', 'entry_time' => '19:00', 'tolerance_minutes' => 30],
                ],
            ]))
            ->assertSessionHasErrors('shifts');
    }

    public function test_aggregate_write_creates_full_structure(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['cycles.manage']);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->post(route('admin.cycles.store'), $this->validPayload())
            ->assertRedirect(route('admin.cycles.index'));

        $cycle = AcademicCycle::query()->where('branch_code', $branch->code)->first();
        $this->assertNotNull($cycle);
        $this->assertSame(1, $cycle->shifts()->count());
        $this->assertSame(2, $cycle->degrees()->count());
        $this->assertSame(2, $cycle->groups()->count());
    }

    public function test_update_syncs_structure_and_removes_deleted_degrees(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['cycles.manage']);

        $cycle = AcademicCycle::factory()->create(['branch_code' => $branch->code]);
        $degree = CycleDegree::factory()->create(['cycle_code' => $cycle->code, 'number' => 1]);
        $degree->groups()->create(['name' => 'A', 'sort_order' => 0, 'is_active' => true]);
        CycleShift::factory()->create(['cycle_code' => $cycle->code]);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->put(route('admin.cycles.update', $cycle), $this->validPayload([
                'degrees' => [['number' => 2, 'groups' => [['name' => 'B']]]],
            ]))
            ->assertRedirect(route('admin.cycles.index'));

        $this->assertSame(0, $cycle->degrees()->where('number', 1)->count());
        $this->assertSame(1, $cycle->degrees()->where('number', 2)->count());
        $this->assertSame('B', $cycle->groups()->first()->name);
    }

    public function test_save_cycle_rejects_updating_a_cycle_from_another_branch(): void
    {
        $branch = Branch::factory()->create();
        $foreignCycle = AcademicCycle::factory()->create([
            'branch_code' => Branch::factory()->create()->code,
            'name' => 'Ciclo ajeno',
        ]);
        $payload = $this->validPayload(['name' => 'Cambio indebido']);

        try {
            app(SaveCycle::class)->handle(
                $branch,
                $foreignCycle,
                [
                    'name' => $payload['name'],
                    'modality' => $payload['modality'],
                    'start_date' => $payload['start_date'],
                    'end_date' => $payload['end_date'],
                    'is_active' => $payload['is_active'],
                ],
                $payload['shifts'],
                $payload['degrees'],
            );

            $this->fail('Expected the aggregate to reject a cycle from another branch.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cycle', $exception->errors());
        }

        $this->assertSame('Ciclo ajeno', $foreignCycle->refresh()->name);
    }

    public function test_no_branch_selected_redirects_to_branches(): void
    {
        $account = $this->createEmployeeAccount(branchCount: 2);
        $this->grantPermissions($account, ['cycles.view']);

        $this->actingAs($account)
            ->get(route('admin.cycles.index'))
            ->assertRedirect(route('branches.index'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ciclo Verano 2026',
            'modality' => 'verano',
            'start_date' => '2026-01-15',
            'end_date' => '2026-03-15',
            'is_active' => true,
            'shifts' => [
                ['name' => 'Mañana', 'entry_time' => '07:00', 'tolerance_minutes' => 60],
            ],
            'degrees' => [
                ['number' => 1, 'groups' => [['name' => 'A']]],
                ['number' => 2, 'groups' => [['name' => 'A']]],
            ],
        ], $overrides);
    }
}
