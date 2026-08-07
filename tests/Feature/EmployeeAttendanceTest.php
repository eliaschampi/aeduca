<?php

namespace Tests\Feature;

use App\Actions\SaveEmployeeSchedule;
use App\Models\Branch;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeSchedule;
use App\Models\User;
use App\Support\EmployeeAttendance\EmployeeAttendanceQueries;
use App\Support\EmployeeAttendance\EmployeeAttendanceState;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class EmployeeAttendanceTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_routes_require_permissions_and_branch_membership(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-02 08:00:00', 'America/Lima'));
        $unauthorized = $this->createEmployeeAccount();
        $branch = $unauthorized->user->branches->sole();

        $this->actingAs($unauthorized)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('employee-attendance.index'))
            ->assertForbidden();

        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['employee_attendance.manage']);
        $other = Branch::factory()->create();
        $foreign = $this->employeeIn($other, '87654321');
        $this->schedule($account->user, $foreign, $other, 1, '07:00', '10:00');

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->postJson(route('employee-attendance.register.store'), ['dni' => $foreign->dni])
            ->assertUnprocessable();
    }

    public function test_schedule_is_simple_day_window_and_multiple_slots_are_allowed(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-02 08:00:00', 'America/Lima'));
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['employee_attendance.manage', 'employees.view']);
        $employee = $this->employeeIn($branch, '11112222');

        $morning = $this->schedule($account->user, $employee, $branch, 1, '07:00', '09:00');
        $afternoon = $this->schedule($account->user, $employee, $branch, 1, '14:00', '16:00');

        $this->assertNotSame($morning->code, $afternoon->code);
        $this->assertSame(2, EmployeeSchedule::query()->where('user_code', $employee->code)->count());

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('admin.employees.show', ['employee' => $employee, 'tab' => 'schedules']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Employees/Profile')
                ->where('active_tab', 'schedules')
                ->has('schedules', 2)
                ->where('can_manage_schedules', true));
    }

    public function test_identical_schedule_slot_is_rejected(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-02 08:00:00', 'America/Lima'));
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $employee = $this->employeeIn($branch, '99998888');
        $this->schedule($account->user, $employee, $branch, 1, '07:00', '09:00');

        $this->expectException(ValidationException::class);
        $this->schedule($account->user, $employee, $branch, 1, '07:00', '09:00');
    }

    public function test_schedule_rejects_overlapping_scan_windows(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-02 08:00:00', 'America/Lima'));
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $employee = $this->employeeIn($branch, '99998887');
        $this->schedule($account->user, $employee, $branch, 1, '07:00', '09:00');

        $this->expectException(ValidationException::class);
        $this->schedule($account->user, $employee, $branch, 1, '09:30', '10:30');
    }

    public function test_profile_shows_own_schedules_without_admin_permission(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-02 08:00:00', 'America/Lima'));
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->schedule($account->user, $account->user, $branch, 1, '08:00', '12:00');

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('profile.show', ['tab' => 'schedules']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Employees/Profile')
                ->where('is_self', true)
                ->where('active_tab', 'schedules')
                ->has('schedules', 1)
                ->where('schedules.0.entry_time', '08:00')
                ->where('can_manage_schedules', false)
                ->where('can_read_attendance', true));
    }

    public function test_scan_resolves_window_and_is_idempotent(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['employee_attendance.manage']);
        $employee = $this->employeeIn($branch, '12345678');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-01 10:00:00', 'America/Lima'));
        $this->schedule($account->user, $employee, $branch, 1, '07:45', '09:00');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-02 07:50:00', 'America/Lima'));
        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->postJson(route('employee-attendance.register.store'), ['dni' => $employee->dni])
            ->assertOk()
            ->assertJsonPath('result.attendance.state', 'late')
            ->assertJsonPath('result.attendance.entry_time', '07:50');

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->postJson(route('employee-attendance.register.store'), ['dni' => $employee->dni])
            ->assertOk()
            ->assertJsonPath('result.status', 'already_registered');

        $this->assertDatabaseCount('employee_attendances', 1);
    }

    public function test_scan_rejects_outside_window(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['employee_attendance.manage']);
        $employee = $this->employeeIn($branch, '23456789');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-01 10:00:00', 'America/Lima'));
        $this->schedule($account->user, $employee, $branch, 1, '07:00', '08:00');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-02 10:00:00', 'America/Lima'));
        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->postJson(route('employee-attendance.register.store'), ['dni' => $employee->dni])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.dni.0',
                'Fuera del rango permitido. Marcación más cercana: 06:00–08:00 (horario 07:00–08:00).',
            );
    }

    public function test_scan_accepts_early_arrival_within_the_institutional_window(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['employee_attendance.manage']);
        $employee = $this->employeeIn($branch, '23456780');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-01 10:00:00', 'America/Lima'));
        $this->schedule($account->user, $employee, $branch, 1, '16:00', '17:00');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-02 14:59:00', 'America/Lima'));
        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->postJson(route('employee-attendance.register.store'), ['dni' => $employee->dni])
            ->assertUnprocessable();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-02 15:10:00', 'America/Lima'));
        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->postJson(route('employee-attendance.register.store'), ['dni' => $employee->dni])
            ->assertOk()
            ->assertJsonPath('result.attendance.state', 'present')
            ->assertJsonPath('result.attendance.entry_time', '15:10');
    }

    public function test_daily_list_derives_pending_and_absent(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['employee_attendance.view']);
        $employee = $this->employeeIn($branch, '34567890');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-01 10:00:00', 'America/Lima'));
        $this->schedule($account->user, $employee, $branch, 1, '07:45', '09:00');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-02 08:00:00', 'America/Lima'));
        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('employee-attendance.index', ['date' => '2026-03-02']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.effective_state', 'pending'));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-02 09:01:00', 'America/Lima'));
        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('employee-attendance.index', ['date' => '2026-03-02']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.effective_state', 'absent'));

        $this->assertDatabaseCount('employee_attendances', 0);
    }

    public function test_manual_create_update_and_delete_today(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-02 10:00:00', 'America/Lima'));
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['employee_attendance.manage']);
        $employee = $this->employeeIn($branch, '45678901');
        $schedule = $this->schedule($account->user, $employee, $branch, 1, '07:00', '12:00');

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->post(route('employee-attendance.manual.store'), [
                'operation' => 'create',
                'schedule_code' => $schedule->code,
                'attendance_date' => '2026-03-02',
                'state' => 'present',
                'entry_time' => '07:10',
                'observation' => 'Registro manual',
            ])
            ->assertRedirect();

        $fact = EmployeeAttendance::query()->sole();
        $this->assertSame(EmployeeAttendanceState::Present, $fact->state);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->post(route('employee-attendance.manual.store'), [
                'operation' => 'update',
                'attendance_code' => $fact->code,
                'attendance_date' => '2026-03-02',
                'state' => 'late',
                'entry_time' => '07:20',
                'observation' => 'Corregido',
            ])
            ->assertRedirect();

        $this->assertSame(EmployeeAttendanceState::Late, $fact->refresh()->state);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->post(route('employee-attendance.manual.store'), [
                'operation' => 'delete',
                'attendance_code' => $fact->code,
                'attendance_date' => '2026-03-02',
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('employee_attendances', 0);
    }

    public function test_history_lists_expected_slots_in_range(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-04 12:00:00', 'America/Lima'));
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['employee_attendance.view']);
        $employee = $this->employeeIn($branch, '56789012');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-01 10:00:00', 'America/Lima'));
        $this->schedule($account->user, $employee, $branch, 1, '07:45', '09:00');
        $this->schedule($account->user, $employee, $branch, 2, '07:45', '09:00');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-04 12:00:00', 'America/Lima'));
        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('employee-attendance.history', [
                'employee' => $employee,
                'from' => '2026-03-02',
                'to' => '2026-03-03',
            ]))
            ->assertRedirect(route('admin.employees.show', [
                'employee' => $employee,
                'tab' => 'attendance',
                'from' => '2026-03-02',
                'to' => '2026-03-03',
            ]));

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('admin.employees.show', [
                'employee' => $employee,
                'tab' => 'attendance',
                'from' => '2026-03-02',
                'to' => '2026-03-03',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Employees/Profile')
                ->where('active_tab', 'attendance')
                ->where('can_read_general', false)
                ->where('roles', [])
                ->where('branches', [])
                ->where('attendance.summary.expected', 2)
                ->has('attendance.history', 2));

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('admin.employees.show', $employee))
            ->assertForbidden();
    }

    public function test_schedule_starts_today_without_inventing_previous_absences(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-09 08:00:00', 'America/Lima'));
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $employee = $this->employeeIn($branch, '60112233');
        $schedule = $this->schedule($account->user, $employee, $branch, 1, '07:00', '09:00');

        $this->assertSame('2026-03-09', $schedule->starts_on->toDateString());
        $rows = app(EmployeeAttendanceQueries::class)->history(
            $employee->code,
            $branch->code,
            '2026-03-02',
            '2026-03-09',
            CarbonImmutable::now('America/Lima'),
        );

        $this->assertCount(1, $rows);
        $this->assertSame('2026-03-09', (string) $rows[0]->attendance_date);
    }

    public function test_historical_schedule_change_closes_old_row_and_applies_replacement_prospectively(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $employee = $this->employeeIn($branch, '60223344');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-02 08:00:00', 'America/Lima'));
        $original = $this->schedule($account->user, $employee, $branch, 1, '07:00', '09:00');
        EmployeeAttendance::query()->create([
            'schedule_code' => $original->code,
            'attendance_date' => '2026-03-02',
            'state' => 'present',
            'entry_time' => '07:00',
            'recording_method' => 'manual',
            'created_by_user_code' => $account->user->code,
            'updated_by_user_code' => $account->user->code,
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-09 08:00:00', 'America/Lima'));
        $replacement = app(SaveEmployeeSchedule::class)->save($account->user, [
            'user_code' => $employee->code,
            'branch_code' => $branch->code,
            'schedule_code' => $original->code,
            'weekday' => 1,
            'entry_time' => '08:00',
            'to_time' => '10:00',
        ]);

        $this->assertNotSame($original->code, $replacement->code);
        $this->assertSame('2026-03-08', $original->refresh()->ends_on->toDateString());
        $this->assertSame('2026-03-09', $replacement->starts_on->toDateString());
        $rows = app(EmployeeAttendanceQueries::class)->history(
            $employee->code,
            $branch->code,
            '2026-03-02',
            '2026-03-09',
            CarbonImmutable::now('America/Lima'),
        );
        $this->assertSame(['08:00:00', '07:00:00'], array_map(
            fn (object $row): string => (string) $row->schedule_entry_time,
            $rows,
        ));
    }

    public function test_forged_current_date_cannot_update_or_delete_historical_fact(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['employee_attendance.manage']);
        $employee = $this->employeeIn($branch, '60334455');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-02 08:00:00', 'America/Lima'));
        $schedule = $this->schedule($account->user, $employee, $branch, 1, '07:00', '09:00');
        $fact = EmployeeAttendance::query()->create([
            'schedule_code' => $schedule->code,
            'attendance_date' => '2026-03-02',
            'state' => 'present',
            'entry_time' => '07:05',
            'recording_method' => 'manual',
            'created_by_user_code' => $account->user->code,
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-09 08:00:00', 'America/Lima'));
        foreach (['update', 'delete'] as $operation) {
            $this->actingAs($account)
                ->withSession(['current_branch_code' => $branch->code])
                ->post(route('employee-attendance.manual.store'), [
                    'operation' => $operation,
                    'attendance_code' => $fact->code,
                    'attendance_date' => '2026-03-09',
                    'state' => 'late',
                    'entry_time' => '07:10',
                ])
                ->assertSessionHasErrors('attendance_code');
        }

        $this->assertDatabaseHas('employee_attendances', [
            'code' => $fact->code,
            'state' => 'present',
        ]);
    }

    public function test_exception_states_store_no_entry_time_and_arrivals_require_it(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-02 08:00:00', 'America/Lima'));
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['employee_attendance.manage']);
        $employee = $this->employeeIn($branch, '60445566');
        $schedule = $this->schedule($account->user, $employee, $branch, 1, '07:00', '09:00');

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->post(route('employee-attendance.manual.store'), [
                'operation' => 'create',
                'schedule_code' => $schedule->code,
                'state' => 'permission',
                'entry_time' => null,
            ])
            ->assertRedirect();
        $this->assertNull(EmployeeAttendance::query()->sole()->entry_time);

        EmployeeAttendance::query()->delete();
        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->post(route('employee-attendance.manual.store'), [
                'operation' => 'create',
                'schedule_code' => $schedule->code,
                'state' => 'present',
                'entry_time' => null,
            ])
            ->assertSessionHasErrors('entry_time');

        $this->expectException(QueryException::class);
        EmployeeAttendance::query()->create([
            'schedule_code' => $schedule->code,
            'attendance_date' => '2026-03-02',
            'state' => 'justified',
            'entry_time' => '07:00',
            'recording_method' => 'manual',
            'created_by_user_code' => $account->user->code,
        ]);
    }

    public function test_overlapping_windows_and_cross_branch_schedule_updates_are_rejected(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-02 08:00:00', 'America/Lima'));
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $otherBranch = Branch::factory()->create();
        $employee = $this->employeeIn($branch, '60556677');
        $employee->branches()->attach($otherBranch);
        $schedule = $this->schedule($account->user, $employee, $branch, 1, '07:00', '10:00');

        try {
            $this->schedule($account->user, $employee, $branch, 1, '09:00', '12:00');
            $this->fail('El solapamiento debió rechazarse.');
        } catch (ValidationException) {
            $this->assertSame(1, EmployeeSchedule::query()->where('user_code', $employee->code)->count());
        }

        try {
            app(SaveEmployeeSchedule::class)->save($account->user, [
                'user_code' => $employee->code,
                'branch_code' => $otherBranch->code,
                'schedule_code' => $schedule->code,
                'weekday' => 1,
                'entry_time' => '08:00',
                'to_time' => '10:00',
            ]);
            $this->fail('El horario no debe cambiar de sede.');
        } catch (ValidationException) {
            $this->assertSame($branch->code, $schedule->refresh()->branch_code);
        }
    }

    public function test_membership_removal_is_rejected_while_current_schedule_depends_on_it(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-02 08:00:00', 'America/Lima'));
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['employees.manage']);
        $branch = Branch::factory()->create();
        $remainingBranch = Branch::factory()->create();
        $employee = $this->employeeIn($branch, '60667788');
        $employee->branches()->attach($remainingBranch);
        $this->schedule($account->user, $employee, $branch, 1, '07:00', '09:00');

        $this->actingAs($account)
            ->put(route('admin.employees.update', $employee), [
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'email' => $employee->email,
                'phone' => $employee->phone,
                'dni' => $employee->dni,
                'employee_role_code' => $employee->employee_role_code,
                'is_active' => true,
                'branch_codes' => [$remainingBranch->code],
            ])
            ->assertSessionHasErrors('branch_codes');

        $this->assertDatabaseHas('user_branches', [
            'user_code' => $employee->code,
            'branch_code' => $branch->code,
        ]);
    }

    public function test_employee_deactivation_requires_open_schedules_to_be_removed_first(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-02 08:00:00', 'America/Lima'));
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['employees.manage']);
        $branch = Branch::factory()->create();
        $employee = $this->employeeIn($branch, '60668800');
        $this->schedule($account->user, $employee, $branch, 1, '07:00', '09:00');

        $this->actingAs($account)
            ->put(route('admin.employees.update', $employee), [
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'email' => $employee->email,
                'phone' => $employee->phone,
                'dni' => $employee->dni,
                'employee_role_code' => $employee->employee_role_code,
                'is_active' => false,
                'branch_codes' => [$branch->code],
            ])
            ->assertSessionHasErrors('is_active');

        $this->assertTrue((bool) $employee->refresh()->is_active);
    }

    public function test_ambiguous_scan_windows_never_create_a_fact(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-02 09:30:00', 'America/Lima'));
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['employee_attendance.manage']);
        $employee = $this->employeeIn($branch, '60778899');
        $this->schedule($account->user, $employee, $branch, 1, '07:00', '10:00');
        EmployeeSchedule::query()->create([
            'user_code' => $employee->code,
            'branch_code' => $branch->code,
            'weekday' => 1,
            'entry_time' => '09:00',
            'to_time' => '12:00',
            'starts_on' => '2026-03-02',
            'created_by_user_code' => $account->user->code,
        ]);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->postJson(route('employee-attendance.register.store'), ['dni' => $employee->dni])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.dni.0',
                'Hay más de un horario válido para este momento. Corrige la configuración antes de registrar.',
            );

        $this->assertDatabaseCount('employee_attendances', 0);
    }

    private function schedule(
        User $actor,
        User $employee,
        Branch $branch,
        int $weekday,
        string $entry,
        string $to,
    ): EmployeeSchedule {
        return app(SaveEmployeeSchedule::class)->save($actor, [
            'user_code' => $employee->code,
            'branch_code' => $branch->code,
            'weekday' => $weekday,
            'entry_time' => $entry,
            'to_time' => $to,
        ]);
    }

    private function employeeIn(Branch $branch, string $dni): User
    {
        $employee = User::factory()->create(['dni' => $dni]);
        $employee->branches()->attach($branch);

        return $employee;
    }
}
