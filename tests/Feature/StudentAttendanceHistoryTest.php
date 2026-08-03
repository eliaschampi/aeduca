<?php

namespace Tests\Feature;

use App\Models\AcademicCycle;
use App\Models\AcademicGroup;
use App\Models\AuthAccount;
use App\Models\Branch;
use App\Models\CycleDegree;
use App\Models\CycleShift;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Support\Attendance\AttendanceMethod;
use App\Support\Attendance\AttendanceState;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StudentAttendanceHistoryTest extends TestCase
{
    public function test_history_derives_missing_rows_and_stored_facts_override_the_effective_state(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 07:00:00', 'America/Lima'));

        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['attendance.view']);
        [$student, $enrollment, $shifts] = $this->enrolledContext($branch);
        $shift = $shifts[0];

        $late = $this->fact(
            $account,
            $enrollment,
            $shift,
            '2026-03-03',
            AttendanceState::Late,
            '2026-03-03 07:05:00',
        );
        $this->fact(
            $account,
            $enrollment,
            $shift,
            '2026-03-04',
            AttendanceState::Justified,
            reason: 'Constancia presentada',
        );
        $this->fact(
            $account,
            $enrollment,
            $shift,
            '2026-03-05',
            AttendanceState::Permission,
            reason: 'Cita médica',
        );
        $present = $this->fact(
            $account,
            $enrollment,
            $shift,
            '2026-03-06',
            AttendanceState::Present,
            '2026-03-06 06:50:00',
        );

        $response = $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('students.attendance', [
                'student' => $student,
                'enrollment' => $enrollment->code,
                'from' => '2026-03-03',
                'to' => '2026-03-10',
            ]))
            ->assertOk();

        $rows = collect($response->inertiaProps('history'))->keyBy('attendance_date');

        $this->assertCount(6, $rows);
        $this->assertSame('pending', $rows['2026-03-10']['effective_state']);
        $this->assertSame('absent', $rows['2026-03-09']['effective_state']);
        $this->assertSame('present', $rows['2026-03-06']['effective_state']);
        $this->assertSame('permission', $rows['2026-03-05']['effective_state']);
        $this->assertSame('justified', $rows['2026-03-04']['effective_state']);
        $this->assertSame('late', $rows['2026-03-03']['effective_state']);
        $this->assertNull($rows['2026-03-10']['attendance_code']);
        $this->assertTrue($rows['2026-03-10']['is_derived']);
        $this->assertSame($present->code, $rows['2026-03-06']['attendance_code']);
        $this->assertFalse($rows['2026-03-06']['is_derived']);
        $this->assertSame($late->code, $rows['2026-03-03']['attendance_code']);

        foreach (['branch_name', 'cycle_name', 'degree_label', 'group_name', 'roll_code', 'shift_name'] as $field) {
            $this->assertArrayNotHasKey($field, $rows['2026-03-10']);
        }

        $this->assertEqualsCanonicalizing([
            'attendance_code',
            'enrollment_code',
            'cycle_shift_code',
            'attendance_date',
            'stored_state',
            'effective_state',
            'state_label',
            'arrival_at',
            'reason',
            'is_derived',
        ], array_keys($rows['2026-03-10']));

        CarbonImmutable::setTestNow();
    }

    public function test_history_requires_one_assigned_shift_and_applies_the_cycle_weekly_pattern(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-16 12:00:00', 'America/Lima'));

        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['attendance.view']);
        [$student, $enrollment, $shifts, $cycle] = $this->enrolledContext(
            $branch,
            cycleAttributes: ['start_date' => '2026-03-09', 'end_date' => '2026-03-31'],
            shiftCount: 2,
        );

        $baseQuery = [
            'student' => $student,
            'enrollment' => $enrollment->code,
            'from' => '2026-03-13',
            'to' => '2026-03-15',
        ];

        $weekdayResponse = $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('students.attendance', $baseQuery))
            ->assertOk();

        $weekdayRows = collect($weekdayResponse->inertiaProps('history'));
        $this->assertCount(1, $weekdayRows);
        $this->assertSame($shifts[0]->code, $weekdayResponse->inertiaProps('filters.shift'));
        $this->assertSame(['2026-03-13'], $weekdayRows->pluck('attendance_date')->unique()->values()->all());
        $this->assertSame([$shifts[0]->code], $weekdayRows->pluck('cycle_shift_code')->unique()->values()->all());

        $cycle->update(['attendance_includes_saturday' => true]);

        $saturdayResponse = $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('students.attendance', $baseQuery))
            ->assertOk();
        $saturdayRows = collect($saturdayResponse->inertiaProps('history'));

        $this->assertCount(2, $saturdayRows);
        $this->assertEqualsCanonicalizing(
            ['2026-03-13', '2026-03-14'],
            $saturdayRows->pluck('attendance_date')->unique()->values()->all(),
        );
        $this->assertNotContains('2026-03-15', $saturdayRows->pluck('attendance_date'));

        $secondShiftResponse = $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('students.attendance', [
                ...$baseQuery,
                'shift' => $shifts[1]->code,
            ]))
            ->assertOk();
        $secondShiftRows = collect($secondShiftResponse->inertiaProps('history'));

        $this->assertCount(2, $secondShiftRows);
        $this->assertSame([$shifts[1]->code], $secondShiftRows->pluck('cycle_shift_code')->unique()->values()->all());

        CarbonImmutable::setTestNow();
    }

    public function test_history_range_is_inclusive_bounded_forgiving_and_never_future(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-01 12:00:00', 'America/Lima'));

        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['attendance.view']);
        [$student, $enrollment, $shifts] = $this->enrolledContext(
            $branch,
            cycleAttributes: [
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'attendance_includes_saturday' => true,
            ],
        );

        $defaultResponse = $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('students.attendance', [
                'student' => $student,
                'enrollment' => $enrollment->code,
            ]))
            ->assertOk();
        $defaultFilters = $defaultResponse->inertiaProps('filters');
        $defaultFrom = CarbonImmutable::parse($defaultFilters['from']);
        $defaultTo = CarbonImmutable::parse($defaultFilters['to']);
        $this->assertSame(30, ((int) $defaultFrom->diffInDays($defaultTo)) + 1);

        $boundedResponse = $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('students.attendance', [
                'student' => $student,
                'enrollment' => $enrollment->code,
                'shift' => $shifts[0]->code,
                'from' => '2026-01-01',
                'to' => '2026-12-31',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.enrollment', $enrollment->code)
                ->where('filters.shift', $shifts[0]->code)
                ->where('filters.from', '2026-05-01')
                ->where('filters.to', '2026-08-01')
                ->has('history', 80));

        $boundedFilters = $boundedResponse->inertiaProps('filters');
        $boundedFrom = CarbonImmutable::parse($boundedFilters['from']);
        $boundedTo = CarbonImmutable::parse($boundedFilters['to']);
        $this->assertSame(93, ((int) $boundedFrom->diffInDays($boundedTo)) + 1);
        $boundedRows = collect($boundedResponse->inertiaProps('history'));
        $this->assertSame('2026-08-01', $boundedRows->first()['attendance_date']);
        $this->assertSame('2026-05-01', $boundedRows->last()['attendance_date']);
        $this->assertSame([$shifts[0]->code], $boundedRows->pluck('cycle_shift_code')->unique()->values()->all());

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('students.attendance', [
                'student' => $student,
                'enrollment' => $enrollment->code,
                'from' => '2026-08-01',
                'to' => '2026-07-01',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.from', '2026-07-01')
                ->where('filters.to', '2026-08-01'));

        CarbonImmutable::setTestNow();
    }

    public function test_history_range_clamps_to_selected_cycle_window(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-01 12:00:00', 'America/Lima'));

        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['attendance.view']);
        [$student, $enrollment, $shifts] = $this->enrolledContext(
            $branch,
            cycleAttributes: [
                'start_date' => '2026-03-01',
                'end_date' => '2026-05-31',
                'attendance_includes_saturday' => true,
            ],
        );

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('students.attendance', [
                'student' => $student,
                'enrollment' => $enrollment->code,
                'shift' => $shifts[0]->code,
                'from' => '2026-01-01',
                'to' => '2026-12-31',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.from', '2026-03-01')
                ->where('filters.to', '2026-05-31'));

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('students.attendance', [
                'student' => $student,
                'enrollment' => $enrollment->code,
                'shift' => $shifts[0]->code,
                'from' => '2026-07-01',
                'to' => '2026-07-15',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.from', '2026-05-31')
                ->where('filters.to', '2026-05-31'));

        CarbonImmutable::setTestNow();
    }

    public function test_history_contexts_enforce_staff_branch_and_student_ownership(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 12:00:00', 'America/Lima'));

        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['attendance.view']);
        $student = Student::factory()->create();
        [, $localEnrollment] = $this->enrolledContext(
            $branch,
            $student,
            cycleAttributes: ['is_active' => false],
            relationAttributes: ['is_active' => false],
        );
        $otherBranch = Branch::factory()->create();
        [, $otherEnrollment] = $this->enrolledContext($otherBranch, $student);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('students.attendance', $student))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('enrollments', 1)
                ->where('enrollments.0.code', $localEnrollment->code)
                ->where('enrollments.0.is_active', false)
                ->where('filters.enrollment', $localEnrollment->code));

        $studentAccount = AuthAccount::factory()->create([
            'user_code' => null,
            'student_code' => $student->code,
            'login' => $student->dni,
            'password' => $this->validPassword,
            'is_active' => true,
        ]);

        $this->actingAs($studentAccount)
            ->get(route('students.attendance', [
                'student' => $student,
                'enrollment' => $otherEnrollment->code,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('is_self', true)
                ->has('enrollments', 2)
                ->where('filters.enrollment', $otherEnrollment->code));

        CarbonImmutable::setTestNow();
    }

    public function test_inaccessible_enrollment_or_unassigned_shift_returns_not_found(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['attendance.view']);
        [$student, $enrollment, , $cycle] = $this->enrolledContext($branch);
        [, $otherStudentEnrollment] = $this->enrolledContext($branch);
        $otherBranch = Branch::factory()->create();
        [, $otherBranchEnrollment] = $this->enrolledContext($otherBranch, $student);
        $unassignedShift = CycleShift::factory()->create(['cycle_code' => $cycle->code]);

        foreach ([$otherStudentEnrollment->code, $otherBranchEnrollment->code] as $inaccessibleCode) {
            $this->actingAs($account)
                ->withSession(['current_branch_code' => $branch->code])
                ->get(route('students.attendance', [
                    'student' => $student,
                    'enrollment' => $inaccessibleCode,
                ]))
                ->assertNotFound();
        }

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('students.attendance', [
                'student' => $student,
                'enrollment' => $enrollment->code,
                'shift' => $unassignedShift->code,
            ]))
            ->assertNotFound();
    }

    public function test_staff_without_a_current_branch_is_redirected_to_branch_selection(): void
    {
        $account = $this->createEmployeeAccount(branchCount: 2);
        $this->grantPermissions($account, ['attendance.view']);
        $student = Student::factory()->create();

        $this->actingAs($account)
            ->get(route('students.attendance', $student))
            ->assertRedirect(route('branches.index'));
    }

    public function test_history_has_a_distinct_empty_state_without_visible_enrollments(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['attendance.view']);
        $student = Student::factory()->create();

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('students.attendance', $student))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('enrollments', 0)
                ->where('filters.enrollment', '')
                ->has('history', 0));
    }

    /**
     * @param  array<string, mixed>  $cycleAttributes
     * @param  array<string, mixed>  $relationAttributes
     * @return array{Student, Enrollment, list<CycleShift>, AcademicCycle}
     */
    private function enrolledContext(
        Branch $branch,
        ?Student $student = null,
        array $cycleAttributes = [],
        int $shiftCount = 1,
        array $relationAttributes = [],
    ): array {
        $cycle = AcademicCycle::factory()->create([
            'branch_code' => $branch->code,
            'start_date' => '2026-03-01',
            'end_date' => '2026-12-15',
            'is_active' => true,
            ...$cycleAttributes,
        ]);
        $degree = CycleDegree::factory()->create([
            'cycle_code' => $cycle->code,
            'number' => 3,
        ]);
        $group = AcademicGroup::factory()->create([
            'cycle_degree_code' => $degree->code,
            'is_active' => $relationAttributes['is_active'] ?? true,
        ]);
        $shifts = collect(range(0, $shiftCount - 1))
            ->map(fn (int $index): CycleShift => CycleShift::factory()->create([
                'cycle_code' => $cycle->code,
                'name' => $index === 0 ? 'Mañana' : 'Tarde',
                'entry_time' => $index === 0 ? '07:00' : '13:00',
                'tolerance_minutes' => 15,
                'sort_order' => $index,
                'is_active' => $relationAttributes['is_active'] ?? true,
            ]))
            ->all();
        $student ??= Student::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'student_code' => $student->code,
            'academic_group_code' => $group->code,
            'cycle_code' => $cycle->code,
            'is_active' => $relationAttributes['is_active'] ?? true,
        ]);
        $enrollment->shifts()->attach(collect($shifts)->pluck('code'));

        return [$student, $enrollment, $shifts, $cycle];
    }

    private function fact(
        AuthAccount $account,
        Enrollment $enrollment,
        CycleShift $shift,
        string $date,
        AttendanceState $state,
        ?string $arrival = null,
        ?string $reason = null,
    ): StudentAttendance {
        return StudentAttendance::query()->create([
            'enrollment_code' => $enrollment->code,
            'cycle_shift_code' => $shift->code,
            'attendance_date' => $date,
            'state' => $state,
            'arrival_at' => $arrival
                ? CarbonImmutable::parse($arrival, 'America/Lima')
                : null,
            'recording_method' => AttendanceMethod::Manual,
            'created_by_user_code' => $account->user->code,
            'reason' => $reason,
        ]);
    }
}
