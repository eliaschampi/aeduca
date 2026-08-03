<?php

namespace Tests\Feature;

use App\Actions\SaveEnrollment;
use App\Models\AcademicCycle;
use App\Models\AcademicGroup;
use App\Models\Branch;
use App\Models\CycleDegree;
use App\Models\CycleShift;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Support\Attendance\AttendanceMethod;
use App\Support\Attendance\AttendanceState;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StudentAttendanceTest extends TestCase
{
    public function test_scan_registers_present_before_entry_and_is_idempotent(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 06:50:00', 'America/Lima'));

        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['attendance.manage']);
        [$student, $enrollment, $shift] = $this->enrolledStudent($branch, '07:00', 15);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->postJson(route('attendance.scan.store'), ['dni' => $student->dni])
            ->assertOk()
            ->assertJsonPath('result.status', 'registered')
            ->assertJsonPath('result.attendance.state', 'present');

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->postJson(route('attendance.scan.store'), ['dni' => $student->dni])
            ->assertOk()
            ->assertJsonPath('result.status', 'already_registered');

        $this->assertSame(1, StudentAttendance::query()->count());
        $this->assertSame(AttendanceState::Present, StudentAttendance::query()->sole()->state);
        $this->assertSame($enrollment->code, StudentAttendance::query()->sole()->enrollment_code);
        $this->assertSame($shift->code, StudentAttendance::query()->sole()->cycle_shift_code);

        CarbonImmutable::setTestNow();
    }

    public function test_scan_registers_late_inside_tolerance_and_rejects_after_close(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['attendance.manage']);
        [$student] = $this->enrolledStudent($branch, '07:00', 10);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 07:05:00', 'America/Lima'));
        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->postJson(route('attendance.scan.store'), ['dni' => $student->dni])
            ->assertOk()
            ->assertJsonPath('result.attendance.state', 'late');

        StudentAttendance::query()->delete();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 07:11:00', 'America/Lima'));
        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->postJson(route('attendance.scan.store'), ['dni' => $student->dni])
            ->assertStatus(422);

        $this->assertSame(0, StudentAttendance::query()->count());
        CarbonImmutable::setTestNow();
    }

    public function test_scan_only_resolves_a_single_tolerance_bounded_shift_window(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['attendance.manage']);
        [$student, $enrollment, $shift, $group, $cycle] = $this->enrolledStudent($branch, '07:00', 15);
        $secondShift = CycleShift::factory()->create([
            'cycle_code' => $cycle->code,
            'entry_time' => '07:10',
            'tolerance_minutes' => 15,
            'is_active' => true,
        ]);
        $enrollment->shifts()->attach($secondShift->code);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 06:40:00', 'America/Lima'));
        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->postJson(route('attendance.scan.store'), ['dni' => $student->dni])
            ->assertStatus(422)
            ->assertJsonPath('message', 'La lectura está fuera del horario de ingreso permitido.');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 07:05:00', 'America/Lima'));
        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->postJson(route('attendance.scan.store'), ['dni' => $student->dni])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Hay más de un turno abierto para este alumno. Usa la lista del día o el registro manual.',
            );

        $this->assertDatabaseMissing('student_attendances', [
            'enrollment_code' => $enrollment->code,
            'cycle_shift_code' => $shift->code,
        ]);
        CarbonImmutable::setTestNow();
    }

    public function test_scan_requires_a_plain_eight_digit_dni(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['attendance.manage']);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->postJson(route('attendance.scan.store'), ['dni' => 'DNI: 12345678'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('dni');
    }

    public function test_scan_does_not_reveal_students_from_other_branches(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['attendance.manage']);
        $other = Branch::factory()->create();
        [$student] = $this->enrolledStudent($other, '07:00', 15);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 06:50:00', 'America/Lima'));
        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->postJson(route('attendance.scan.store'), ['dni' => $student->dni])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No se pudo registrar la lectura con esos datos.');

        CarbonImmutable::setTestNow();
    }

    public function test_daily_list_shows_expected_students_and_derived_absent(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 12:00:00', 'America/Lima'));

        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['attendance.view']);
        [$student, $enrollment, $shift, $group, $cycle, $degree] = $this->enrolledStudent(
            $branch,
            '07:00',
            10,
        );

        $response = $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('attendance.index', [
                'date' => '2026-03-10',
                'cycle' => $cycle->code,
                'degree' => $degree->number,
                'group' => $group->code,
                'shift' => $shift->code,
            ]))
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Attendance/Index')
            ->where('summary.expected', 1)
            ->where('summary.absent', 1)
            ->where('attendance.data.0.student_code', $student->code)
            ->where('attendance.data.0.effective_state', 'absent')
            ->where('attendance.data.0.enrollment_code', $enrollment->code));

        CarbonImmutable::setTestNow();
    }

    public function test_expected_day_predicate_uses_the_cycle_saturday_rule_and_never_sunday(): void
    {
        $expected = fn (string $date, ?bool $includesSaturday): int => (int) DB::scalar(
            <<<'SQL'
                SELECT CASE
                    WHEN student_attendance_is_expected_day(?::date, ?) THEN 1
                    ELSE 0
                END
                SQL,
            [$date, $includesSaturday],
        );

        $this->assertSame(1, $expected('2026-03-09', false));
        $this->assertSame(0, $expected('2026-03-14', false));
        $this->assertSame(0, $expected('2026-03-14', null));
        $this->assertSame(1, $expected('2026-03-14', true));
        $this->assertSame(0, $expected('2026-03-15', true));
    }

    public function test_non_expected_saturday_is_excluded_from_roster_scan_and_manual_writes(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-14 06:50:00', 'America/Lima'));

        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['attendance.manage']);
        [$student, $enrollment, $shift, $group, $cycle, $degree] = $this->enrolledStudent(
            $branch,
            '07:00',
            15,
        );

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('attendance.index', [
                'date' => '2026-03-14',
                'cycle' => $cycle->code,
                'degree' => $degree->number,
                'group' => $group->code,
                'shift' => $shift->code,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.expected', 0)
                ->where('attendance.total', 0));

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->postJson(route('attendance.scan.store'), ['dni' => $student->dni])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No se pudo registrar la lectura con esos datos.');

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->post(route('attendance.manual.store'), [
                'operation' => 'arrival',
                'enrollment_code' => $enrollment->code,
                'cycle_shift_code' => $shift->code,
                'attendance_date' => '2026-03-14',
                'arrival_at' => '06:50',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('attendance_date');

        $this->assertDatabaseCount('student_attendances', 0);
        CarbonImmutable::setTestNow();
    }

    public function test_expected_saturday_is_available_to_roster_scan_and_manual_writes(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-14 06:50:00', 'America/Lima'));

        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['attendance.manage']);
        [$student, $enrollment, $shift, $group, $cycle, $degree] = $this->enrolledStudent(
            $branch,
            '07:00',
            15,
        );
        $cycle->update(['attendance_includes_saturday' => true]);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('attendance.index', [
                'date' => '2026-03-14',
                'cycle' => $cycle->code,
                'degree' => $degree->number,
                'group' => $group->code,
                'shift' => $shift->code,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.expected', 1)
                ->where('attendance.total', 1));

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->postJson(route('attendance.scan.store'), ['dni' => $student->dni])
            ->assertOk()
            ->assertJsonPath('result.attendance.state', 'present');

        StudentAttendance::query()->delete();

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->post(route('attendance.manual.store'), [
                'operation' => 'arrival',
                'enrollment_code' => $enrollment->code,
                'cycle_shift_code' => $shift->code,
                'attendance_date' => '2026-03-14',
                'arrival_at' => '06:50',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('student_attendances', [
            'enrollment_code' => $enrollment->code,
            'cycle_shift_code' => $shift->code,
            'attendance_date' => '2026-03-14',
        ]);
        CarbonImmutable::setTestNow();
    }

    public function test_manual_permission_and_justify_rules(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['attendance.manage']);
        [, $enrollment, $shift] = $this->enrolledStudent($branch, '07:00', 15);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 06:30:00', 'America/Lima'));
        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->post(route('attendance.manual.store'), [
                'operation' => 'permission',
                'enrollment_code' => $enrollment->code,
                'cycle_shift_code' => $shift->code,
                'attendance_date' => '2026-03-10',
                'reason' => 'Cita médica',
            ])
            ->assertRedirect();

        $this->assertSame(AttendanceState::Permission, StudentAttendance::query()->sole()->state);

        StudentAttendance::query()->delete();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 08:00:00', 'America/Lima'));

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->post(route('attendance.manual.store'), [
                'operation' => 'justify',
                'enrollment_code' => $enrollment->code,
                'cycle_shift_code' => $shift->code,
                'attendance_date' => '2026-03-10',
                'reason' => 'Constancia presentada',
            ])
            ->assertRedirect();

        $this->assertSame(AttendanceState::Justified, StudentAttendance::query()->sole()->state);
        CarbonImmutable::setTestNow();
    }

    public function test_manual_operations_do_not_overwrite_an_existing_fact_without_correction(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['attendance.manage']);
        [, $enrollment, $shift] = $this->enrolledStudent($branch, '07:00', 15);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 06:30:00', 'America/Lima'));
        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->post(route('attendance.manual.store'), [
                'operation' => 'permission',
                'enrollment_code' => $enrollment->code,
                'cycle_shift_code' => $shift->code,
                'attendance_date' => '2026-03-10',
                'reason' => 'Cita médica',
            ])
            ->assertRedirect();

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->post(route('attendance.manual.store'), [
                'operation' => 'arrival',
                'enrollment_code' => $enrollment->code,
                'cycle_shift_code' => $shift->code,
                'attendance_date' => '2026-03-10',
                'arrival_at' => '06:45',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('operation');

        $this->assertSame(AttendanceState::Permission, StudentAttendance::query()->sole()->state);
        CarbonImmutable::setTestNow();
    }

    public function test_scan_does_not_overwrite_an_existing_manual_exception(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['attendance.manage']);
        [$student, $enrollment, $shift] = $this->enrolledStudent($branch, '07:00', 15);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 06:30:00', 'America/Lima'));
        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->post(route('attendance.manual.store'), [
                'operation' => 'permission',
                'enrollment_code' => $enrollment->code,
                'cycle_shift_code' => $shift->code,
                'attendance_date' => '2026-03-10',
                'reason' => 'Cita médica',
            ])
            ->assertRedirect();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 06:50:00', 'America/Lima'));
        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->postJson(route('attendance.scan.store'), ['dni' => $student->dni])
            ->assertOk()
            ->assertJsonPath('result.status', 'already_registered')
            ->assertJsonPath('result.attendance.state', 'permission');

        $fact = StudentAttendance::query()->sole();
        $this->assertSame(AttendanceState::Permission, $fact->state);
        $this->assertSame(AttendanceMethod::Manual, $fact->recording_method);
        $this->assertSame('Cita médica', $fact->reason);
        CarbonImmutable::setTestNow();
    }

    public function test_inactive_cycle_is_not_an_operational_attendance_context(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 06:50:00', 'America/Lima'));

        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['attendance.manage']);
        [$student, $enrollment, $shift, $group, $cycle, $degree] = $this->enrolledStudent(
            $branch,
            '07:00',
            15,
        );
        $cycle->update(['is_active' => false]);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('attendance.index', [
                'date' => '2026-03-10',
                'cycle' => $cycle->code,
                'degree' => $degree->number,
                'group' => $group->code,
                'shift' => $shift->code,
            ]))
            ->assertNotFound();

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->postJson(route('attendance.scan.store'), ['dni' => $student->dni])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No se pudo registrar la lectura con esos datos.');

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->post(route('attendance.manual.store'), [
                'operation' => 'arrival',
                'enrollment_code' => $enrollment->code,
                'cycle_shift_code' => $shift->code,
                'attendance_date' => '2026-03-10',
                'arrival_at' => '06:50',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('enrollment_code');

        $this->assertDatabaseCount('student_attendances', 0);
        CarbonImmutable::setTestNow();
    }

    public function test_sunday_is_not_an_attendance_day_and_database_rejects_a_fact(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['attendance.manage', 'attendance.view']);
        [, $enrollment, $shift, $group, $cycle, $degree] = $this->enrolledStudent($branch, '07:00', 15);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-08 08:00:00', 'America/Lima'));
        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('attendance.index', [
                'date' => '2026-03-08',
                'cycle' => $cycle->code,
                'degree' => $degree->number,
                'group' => $group->code,
                'shift' => $shift->code,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('context_complete', true)
                ->where('summary.expected', 0)
                ->where('attendance.total', 0));

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->post(route('attendance.manual.store'), [
                'operation' => 'justify',
                'enrollment_code' => $enrollment->code,
                'cycle_shift_code' => $shift->code,
                'attendance_date' => '2026-03-08',
                'reason' => 'No hay clases los domingos',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('attendance_date');

        CarbonImmutable::setTestNow();
        $this->expectException(QueryException::class);

        StudentAttendance::query()->create([
            'enrollment_code' => $enrollment->code,
            'cycle_shift_code' => $shift->code,
            'attendance_date' => '2026-03-08',
            'state' => AttendanceState::Present,
            'arrival_at' => CarbonImmutable::parse('2026-03-08 06:55:00', 'America/Lima'),
            'recording_method' => AttendanceMethod::Manual,
            'created_by_user_code' => $account->user->code,
        ]);
    }

    public function test_database_requires_the_shift_selected_by_the_enrollment(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        [, $enrollment, , , $cycle] = $this->enrolledStudent($branch, '07:00', 15);
        $unassignedShift = CycleShift::factory()->create([
            'cycle_code' => $cycle->code,
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);

        StudentAttendance::query()->create([
            'enrollment_code' => $enrollment->code,
            'cycle_shift_code' => $unassignedShift->code,
            'attendance_date' => '2026-03-10',
            'state' => AttendanceState::Present,
            'arrival_at' => CarbonImmutable::parse('2026-03-10 06:55:00', 'America/Lima'),
            'recording_method' => AttendanceMethod::Manual,
            'created_by_user_code' => $account->user->code,
        ]);
    }

    public function test_database_rejects_scan_metadata_that_is_not_automatic(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        [, $enrollment, $shift] = $this->enrolledStudent($branch, '07:00', 15);

        $this->expectException(QueryException::class);

        StudentAttendance::query()->create([
            'enrollment_code' => $enrollment->code,
            'cycle_shift_code' => $shift->code,
            'attendance_date' => '2026-03-10',
            'state' => AttendanceState::Present,
            'arrival_at' => CarbonImmutable::parse('2026-03-10 06:55:00', 'America/Lima'),
            'recording_method' => AttendanceMethod::Scan,
            'created_by_user_code' => $account->user->code,
            'reason' => 'No corresponde a una lectura automática.',
        ]);
    }

    public function test_an_enrollment_with_attendance_cannot_be_deleted(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['enrollments.delete', 'students.view']);
        [$student, $enrollment, $shift] = $this->enrolledStudent($branch, '07:00', 15);

        StudentAttendance::query()->create([
            'enrollment_code' => $enrollment->code,
            'cycle_shift_code' => $shift->code,
            'attendance_date' => '2026-03-10',
            'state' => AttendanceState::Present,
            'arrival_at' => CarbonImmutable::parse('2026-03-10 06:55:00', 'America/Lima'),
            'recording_method' => AttendanceMethod::Manual,
            'created_by_user_code' => $account->user->code,
        ]);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->delete(route('enrollments.destroy', $enrollment))
            ->assertRedirect(route('students.show', $student))
            ->assertInertiaFlash(
                'info',
                'No se puede eliminar una matrícula que ya tiene asistencia registrada.',
            );

        $this->assertDatabaseHas('enrollments', ['code' => $enrollment->code]);
        $this->assertDatabaseHas('student_attendances', ['enrollment_code' => $enrollment->code]);
    }

    public function test_an_enrollment_shift_with_attendance_cannot_be_removed(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 07:00:00', 'America/Lima'));

        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        [$student, $enrollment, $shift, $group, $cycle] = $this->enrolledStudent($branch, '07:00', 15);
        $replacementShift = CycleShift::factory()->create([
            'cycle_code' => $cycle->code,
            'is_active' => true,
        ]);
        $enrollment->shifts()->attach($replacementShift->code);

        StudentAttendance::query()->create([
            'enrollment_code' => $enrollment->code,
            'cycle_shift_code' => $shift->code,
            'attendance_date' => '2026-03-10',
            'state' => AttendanceState::Present,
            'arrival_at' => CarbonImmutable::parse('2026-03-10 06:55:00', 'America/Lima'),
            'recording_method' => AttendanceMethod::Manual,
            'created_by_user_code' => $account->user->code,
        ]);

        try {
            app(SaveEnrollment::class)->handle(
                $branch,
                $student,
                $enrollment,
                $group->code,
                [$replacementShift->code],
                true,
                null,
            );
            $this->fail('Expected removing the shift with attendance to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'No puedes quitar un turno que ya tiene asistencia registrada.',
                $exception->errors()['shift_codes'][0],
            );
        }

        $this->assertDatabaseHas('enrollment_shifts', [
            'enrollment_code' => $enrollment->code,
            'cycle_shift_code' => $shift->code,
        ]);
        CarbonImmutable::setTestNow();
    }

    public function test_attendance_routes_require_permissions(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $student = Student::factory()->create();

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('attendance.index'))
            ->assertForbidden();

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('attendance.scan'))
            ->assertForbidden();

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->postJson(route('attendance.scan.store'), [])
            ->assertForbidden();

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->post(route('attendance.manual.store'), [])
            ->assertForbidden();

        $this->actingAs($account)
            ->get(route('students.attendance', $student))
            ->assertForbidden();

        $viewAccount = $this->createEmployeeAccount();
        $viewBranch = $viewAccount->user->branches->sole();
        $this->grantPermissions($viewAccount, ['attendance.view']);

        $this->actingAs($viewAccount)
            ->withSession(['current_branch_code' => $viewBranch->code])
            ->postJson(route('attendance.scan.store'), [])
            ->assertForbidden();

        $this->actingAs($viewAccount)
            ->withSession(['current_branch_code' => $viewBranch->code])
            ->post(route('attendance.manual.store'), [])
            ->assertForbidden();
    }

    public function test_scan_page_has_no_academic_selectors_payload(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['attendance.manage']);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('attendance.scan'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Scan')
                ->has('branch')
                ->missing('contexts')
                ->missing('selected_context'));
    }

    /**
     * @return array{Student, Enrollment, CycleShift, AcademicGroup, AcademicCycle, CycleDegree}
     */
    private function enrolledStudent(
        Branch $branch,
        string $entryTime = '07:00',
        int $tolerance = 15,
    ): array {
        $cycle = AcademicCycle::factory()->create([
            'branch_code' => $branch->code,
            'start_date' => '2026-03-01',
            'end_date' => '2026-12-15',
            'is_active' => true,
        ]);
        $degree = CycleDegree::factory()->create([
            'cycle_code' => $cycle->code,
            'number' => 3,
        ]);
        $group = AcademicGroup::factory()->create([
            'cycle_degree_code' => $degree->code,
            'is_active' => true,
        ]);
        $shift = CycleShift::factory()->create([
            'cycle_code' => $cycle->code,
            'entry_time' => $entryTime,
            'tolerance_minutes' => $tolerance,
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $student = Student::factory()->create(['is_active' => true]);
        $enrollment = Enrollment::factory()->create([
            'student_code' => $student->code,
            'academic_group_code' => $group->code,
            'cycle_code' => $cycle->code,
            'is_active' => true,
        ]);
        $enrollment->shifts()->attach($shift->code);

        return [$student, $enrollment, $shift, $group, $cycle, $degree];
    }
}
