<?php

namespace Tests\Feature;

use App\Models\AcademicCycle;
use App\Models\AcademicGroup;
use App\Models\Branch;
use App\Models\CycleDegree;
use App\Models\CycleShift;
use App\Models\Enrollment;
use App\Models\PaymentObligation;
use App\Models\Student;
use App\Support\Academic\AcademicLevel;
use App\Support\Business\BusinessCalendar;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EnrollmentManagementTest extends TestCase
{
    public function test_enrollment_permissions_open_profile_history_but_protect_writes(): void
    {
        $student = Student::factory()->create();
        $viewer = $this->createEmployeeAccount();
        $this->grantPermissions($viewer, ['enrollments.view']);

        $this->actingAs($viewer)
            ->get(route('students.show', $student))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_view_enrollments', true)
                ->where('can_manage_enrollments', false));

        $this->actingAs($viewer)
            ->get(route('students.enrollments.create', $student))
            ->assertForbidden();

        $manager = $this->createEmployeeAccount();
        $this->grantPermissions($manager, ['enrollments.manage']);

        $this->actingAs($manager)
            ->get(route('students.enrollments.create', $student))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Enrollments/Form')
                ->where('student.code', $student->code));
    }

    public function test_create_replaces_the_active_enrollment_and_creates_assignment_and_obligations_atomically(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['enrollments.manage']);
        $branch = $account->user->branches->sole();
        [$cycle, $group, $firstShift, $secondShift] = $this->offering($branch);
        $student = Student::factory()->create();
        $previous = Enrollment::factory()->create([
            'student_code' => $student->code,
            'academic_group_code' => $group->code,
            'roll_code' => '0099',
        ]);

        $response = $this->actingAs($account)
            ->post(route('students.enrollments.store', $student), $this->validPayload(
                $group,
                [$firstShift, $secondShift],
                [
                    ['concept' => ' Matrícula ', 'amount' => '150.50', 'due_date' => '2026-07-31'],
                    ['concept' => 'Pensión agosto', 'amount' => 300, 'due_date' => '2026-08-31'],
                ],
            ));

        $created = Enrollment::query()
            ->where('student_code', $student->code)
            ->whereKeyNot($previous->code)
            ->sole();

        $response
            ->assertRedirect(route('students.show', $student))
            ->assertInertiaFlash('success', 'Matrícula registrada');
        $this->assertFalse($previous->fresh()->is_active);
        $this->assertTrue($created->is_active);
        $this->assertSame('0001', $created->roll_code);
        $this->assertSame($group->code, $created->academic_group_code);
        $this->assertEqualsCanonicalizing(
            [$firstShift->code, $secondShift->code],
            $created->shifts()->pluck('cycle_shifts.code')->all(),
        );
        $this->assertSame(2, $created->obligations()->count());
        $this->assertSame('Matrícula', $created->obligations()->orderBy('due_date')->first()->concept);

        $this->actingAs($account)
            ->get(route('students.show', $student))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('student.enrollments', 2)
                ->where('student.enrollments.0.code', $created->code)
                ->where('student.enrollments.0.cycle_name', $cycle->name)
                ->where('student.enrollments.0.obligations_total', '450.50')
                ->where('student.enrollments.0.status', 'active'));
    }

    public function test_enrollment_rejects_a_group_outside_authorized_branches_and_a_shift_from_another_cycle(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['enrollments.manage']);
        $branch = $account->user->branches->sole();
        [, $group, $shift] = $this->offering($branch);
        [, $foreignGroup, $foreignShift] = $this->offering(Branch::factory()->create());
        $student = Student::factory()->create();

        $this->actingAs($account)
            ->post(
                route('students.enrollments.store', $student),
                $this->validPayload($foreignGroup, [$foreignShift]),
            )
            ->assertSessionHasErrors('academic_group_code');

        $this->actingAs($account)
            ->post(
                route('students.enrollments.store', $student),
                $this->validPayload($group, [$shift, $foreignShift]),
            )
            ->assertSessionHasErrors('shift_codes');

        $this->assertSame(0, $student->enrollments()->count());
        $this->assertSame(0, PaymentObligation::query()->count());
    }

    public function test_update_preserves_obligation_identity_and_nested_enrollment_ownership(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['enrollments.manage']);
        $branch = $account->user->branches->sole();
        [, $group, $shift] = $this->offering($branch);
        $student = Student::factory()->create();
        $otherStudent = Student::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'student_code' => $student->code,
            'academic_group_code' => $group->code,
            'roll_code' => '0042',
        ]);
        $enrollment->shifts()->attach($shift);
        $obligation = PaymentObligation::factory()->create([
            'enrollment_code' => $enrollment->code,
            'concept' => 'Pensión',
            'amount' => 250,
            'due_date' => '2026-07-31',
        ]);

        $this->actingAs($account)
            ->put(
                route('students.enrollments.update', [$student, $enrollment]),
                $this->validPayload($group, [$shift], [[
                    'code' => $obligation->code,
                    'concept' => 'Pensión actualizada',
                    'amount' => '275.00',
                    'due_date' => '2026-08-05',
                ]]),
            )
            ->assertRedirect(route('students.show', $student))
            ->assertInertiaFlash('success', 'Matrícula actualizada');

        $obligation->refresh();
        $this->assertSame('Pensión actualizada', $obligation->concept);
        $this->assertSame('275.00', $obligation->amount);
        $this->assertSame('2026-08-05', $obligation->due_date->toDateString());

        $this->actingAs($account)
            ->put(
                route('students.enrollments.update', [$otherStudent, $enrollment]),
                $this->validPayload($group, [$shift]),
            )
            ->assertNotFound();
    }

    public function test_postgresql_enforces_one_active_enrollment_per_student_and_roll_code(): void
    {
        $student = Student::factory()->create();
        $first = Enrollment::factory()->create([
            'student_code' => $student->code,
            'roll_code' => '0042',
        ]);

        $this->assertQueryFails(fn () => Enrollment::factory()->create([
            'student_code' => $student->code,
            'roll_code' => '0043',
        ]));
        $this->assertQueryFails(fn () => Enrollment::factory()->create([
            'roll_code' => $first->roll_code,
        ]));

        Enrollment::factory()->create([
            'student_code' => $student->code,
            'roll_code' => '0042',
            'is_active' => false,
        ]);
        $this->addToAssertionCount(1);
    }

    /**
     * @return array{AcademicCycle, AcademicGroup, CycleShift, CycleShift}
     */
    private function offering(Branch $branch): array
    {
        $today = BusinessCalendar::today();
        $cycle = AcademicCycle::factory()->create([
            'branch_code' => $branch->code,
            'level' => AcademicLevel::Primary,
            'start_date' => $today->subMonth()->toDateString(),
            'end_date' => $today->addMonths(6)->toDateString(),
            'is_active' => true,
        ]);
        $degree = CycleDegree::factory()->create([
            'cycle_code' => $cycle->code,
            'number' => 3,
        ]);
        $group = AcademicGroup::factory()->create([
            'cycle_degree_code' => $degree->code,
            'name' => 'A',
        ]);
        $firstShift = CycleShift::factory()->create([
            'cycle_code' => $cycle->code,
            'name' => 'Mañana',
        ]);
        $secondShift = CycleShift::factory()->create([
            'cycle_code' => $cycle->code,
            'name' => 'Tarde',
        ]);

        return [$cycle, $group, $firstShift, $secondShift];
    }

    /**
     * @param  list<CycleShift>  $shifts
     * @param  list<array<string, mixed>>|null  $obligations
     * @return array<string, mixed>
     */
    private function validPayload(
        AcademicGroup $group,
        array $shifts,
        ?array $obligations = null,
    ): array {
        return [
            'academic_group_code' => $group->code,
            'shift_codes' => array_map(
                fn (CycleShift $shift): string => $shift->code,
                $shifts,
            ),
            'is_active' => true,
            'observation' => 'Nueva asignación',
            'obligations' => $obligations ?? [[
                'concept' => 'Matrícula',
                'amount' => '150.00',
                'due_date' => '2026-07-31',
            ]],
        ];
    }

    private function assertQueryFails(callable $operation): void
    {
        try {
            DB::transaction(fn () => $operation());
            $this->fail('Expected the database invariant to reject the write.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
