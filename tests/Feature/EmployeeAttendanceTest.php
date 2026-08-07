<?php

namespace Tests\Feature;

use App\Actions\SaveEmployeeSchedule;
use App\Models\Branch;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeSchedule;
use App\Models\User;
use App\Support\EmployeeAttendance\EmployeeAttendanceState;
use Carbon\CarbonImmutable;
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
            ->assertJsonPath('errors.dni.0', 'Fuera del rango permitido. Ventana más cercana: 07:00–08:00.');
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
        // Profile of another employee requires employees.view; attendance data needs attendance.view.
        $this->grantPermissions($account, ['employee_attendance.view', 'employees.view']);
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
                ->where('attendance.summary.expected', 2)
                ->has('attendance.history', 2));
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
