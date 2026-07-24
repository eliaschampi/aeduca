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
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EnrollmentManagementTest extends TestCase
{
    public function test_manager_creates_enrollment_with_database_reserved_roll_and_shifts(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['enrollments.manage']);
        $student = Student::factory()->create();
        [$group, $shifts] = $this->academicStructure($branch);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->post(route('enrollments.store', $student), [
                'academic_group_code' => $group->code,
                'shift_codes' => $shifts->pluck('code')->all(),
                'observation' => 'Ingreso regular',
            ])
            ->assertRedirect(route('enrollments.index'));

        $enrollment = Enrollment::query()->sole();
        $this->assertMatchesRegularExpression('/^[0-9]{4}$/', $enrollment->roll_code);
        $this->assertSame($group->cycleDegree->cycle_code, $enrollment->cycle_code);
        $this->assertTrue((bool) $enrollment->is_active);
        $this->assertSame(2, $enrollment->shifts()->count());
        $this->assertDatabaseHas('student_roster', [
            'enrollment_code' => $enrollment->code,
            'branch_code' => $branch->code,
        ]);
    }

    public function test_same_cycle_retry_is_rejected_without_replacing_the_enrollment(): void
    {
        $branch = Branch::factory()->create();
        $student = Student::factory()->create();
        [$firstGroup, $shifts, $cycle] = $this->academicStructure($branch);
        $secondGroup = AcademicGroup::factory()->create([
            'cycle_degree_code' => $firstGroup->cycle_degree_code,
        ]);
        $action = app(SaveEnrollment::class);

        $first = $action->handle(
            $branch,
            $student,
            null,
            $firstGroup->code,
            [$shifts->first()->code],
            true,
            null,
        );

        try {
            $action->handle(
                $branch,
                $student,
                null,
                $secondGroup->code,
                [$shifts->last()->code],
                true,
                null,
            );
            $this->fail('Expected the existing cycle enrollment to be reused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('enrollment', $exception->errors());
        }

        $first->refresh();
        $this->assertSame($cycle->code, $first->cycle_code);
        $this->assertSame($firstGroup->code, $first->academic_group_code);
        $this->assertTrue((bool) $first->is_active);
        $this->assertSame(1, $student->enrollments()->count());
    }

    public function test_student_cannot_join_another_unfinished_cycle(): void
    {
        $branch = Branch::factory()->create();
        $student = Student::factory()->create();
        [$firstGroup, $firstShifts] = $this->academicStructure($branch);
        [$secondGroup, $secondShifts] = $this->academicStructure($branch);
        $action = app(SaveEnrollment::class);

        $first = $action->handle(
            $branch,
            $student,
            null,
            $firstGroup->code,
            [$firstShifts->first()->code],
            false,
            null,
        );

        try {
            $action->handle(
                $branch,
                $student,
                null,
                $secondGroup->code,
                [$secondShifts->first()->code],
                true,
                null,
            );
            $this->fail('Expected the unfinished cycle to prevent another enrollment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('enrollment', $exception->errors());
        }

        $this->assertFalse((bool) $first->fresh()->is_active);
        $this->assertSame(1, $student->enrollments()->count());
    }

    public function test_edit_preserves_identity_and_cannot_move_enrollment_to_another_cycle(): void
    {
        $branch = Branch::factory()->create();
        $student = Student::factory()->create();
        [$group, $shifts] = $this->academicStructure($branch);
        [$foreignCycleGroup, $foreignCycleShifts] = $this->academicStructure($branch);
        $action = app(SaveEnrollment::class);

        $enrollment = $action->handle(
            $branch,
            $student,
            null,
            $group->code,
            [$shifts->first()->code],
            true,
            null,
        );
        $originalCode = $enrollment->code;
        $originalRollCode = $enrollment->roll_code;

        $updated = $action->handle(
            $branch,
            $student,
            $enrollment,
            $group->code,
            [$shifts->last()->code],
            true,
            'Actualizada',
        );

        $this->assertSame($originalCode, $updated->code);
        $this->assertSame($originalRollCode, $updated->roll_code);
        $this->assertSame([$shifts->last()->code], $updated->shifts->pluck('code')->all());

        try {
            $action->handle(
                $branch,
                $student,
                $updated,
                $foreignCycleGroup->code,
                [$foreignCycleShifts->first()->code],
                true,
                null,
            );
            $this->fail('Expected an enrollment move between cycles to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('academic_group_code', $exception->errors());
        }

        $updated->refresh();
        $this->assertSame($originalCode, $updated->code);
        $this->assertSame($originalRollCode, $updated->roll_code);
        $this->assertSame($group->code, $updated->academic_group_code);
        $this->assertSame(1, $student->enrollments()->count());
    }

    public function test_group_and_shifts_must_belong_to_current_branch_and_same_cycle(): void
    {
        $branch = Branch::factory()->create();
        $student = Student::factory()->create();
        [$group] = $this->academicStructure($branch);
        [, $foreignShifts] = $this->academicStructure();

        try {
            app(SaveEnrollment::class)->handle(
                $branch,
                $student,
                null,
                $group->code,
                [$foreignShifts->first()->code],
                true,
                null,
            );
            $this->fail('Expected the enrollment aggregate to reject a foreign shift.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('shift_codes', $exception->errors());
        }

        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_foreign_branch_write_is_rejected_without_changing_existing_enrollment(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['enrollments.manage']);
        $student = Student::factory()->create();
        [$foreignGroup, $foreignShifts] = $this->academicStructure();

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->post(route('enrollments.store', $student), [
                'academic_group_code' => $foreignGroup->code,
                'shift_codes' => [$foreignShifts->first()->code],
            ])
            ->assertSessionHasErrors('academic_group_code');

        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_database_protects_one_enrollment_per_student_and_cycle(): void
    {
        $student = Student::factory()->create();
        [$group] = $this->academicStructure();
        Enrollment::factory()->create([
            'student_code' => $student->code,
            'academic_group_code' => $group->code,
            'roll_code' => '0007',
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);

        Enrollment::factory()->create([
            'student_code' => $student->code,
            'academic_group_code' => $group->code,
            'roll_code' => '0008',
            'is_active' => false,
        ]);
    }

    public function test_database_protects_roll_uniqueness_within_the_cycle(): void
    {
        [$group] = $this->academicStructure();
        Enrollment::factory()->create([
            'academic_group_code' => $group->code,
            'roll_code' => '0007',
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);

        Enrollment::factory()->create([
            'academic_group_code' => $group->code,
            'roll_code' => '0007',
            'is_active' => false,
        ]);
    }

    public function test_roll_reservation_returns_distinct_four_digit_codes(): void
    {
        [, , $cycle] = $this->academicStructure();
        $codes = collect(range(1, 30))->map(
            fn (): string => trim((string) DB::selectOne(
                'SELECT reserve_enrollment_roll_code(?) AS code',
                [$cycle->code],
            )->code),
        );

        $this->assertCount(30, $codes->unique());
        $this->assertTrue($codes->every(
            fn (string $code): bool => preg_match('/^[0-9]{4}$/', $code) === 1,
        ));
    }

    public function test_cycle_end_date_derives_finalized_without_persisting_a_status(): void
    {
        [$group, , $cycle] = $this->academicStructure();
        $enrollment = Enrollment::factory()->create([
            'academic_group_code' => $group->code,
            'is_active' => true,
        ]);
        $cycle->update([
            'end_date' => CarbonImmutable::now('America/Lima')->subDay()->toDateString(),
            'start_date' => CarbonImmutable::now('America/Lima')->subYear()->toDateString(),
        ]);

        $overview = DB::table('student_enrollment_overview')
            ->where('enrollment_code', $enrollment->code)
            ->sole();

        $this->assertSame('finalized', $overview->enrollment_status);
        $this->assertTrue((bool) $overview->is_active);
        $this->assertFalse(Schema::hasColumn('enrollments', 'status'));
    }

    public function test_finished_cycle_allows_a_new_enrollment_without_rewriting_history(): void
    {
        $branch = Branch::factory()->create();
        $student = Student::factory()->create();
        [$oldGroup, $oldShifts, $oldCycle] = $this->academicStructure($branch);
        [$newGroup, $newShifts] = $this->academicStructure($branch);
        $action = app(SaveEnrollment::class);

        $oldEnrollment = $action->handle(
            $branch,
            $student,
            null,
            $oldGroup->code,
            [$oldShifts->first()->code],
            true,
            null,
        );
        $oldCycle->update([
            'start_date' => CarbonImmutable::now('America/Lima')->subYear()->toDateString(),
            'end_date' => CarbonImmutable::now('America/Lima')->subDay()->toDateString(),
        ]);

        $newEnrollment = $action->handle(
            $branch,
            $student,
            null,
            $newGroup->code,
            [$newShifts->first()->code],
            true,
            null,
        );

        $this->assertNotSame($oldEnrollment->code, $newEnrollment->code);
        $this->assertTrue((bool) $oldEnrollment->fresh()->is_active);
        $this->assertSame(2, $student->enrollments()->count());
        $this->assertSame(
            'finalized',
            DB::table('student_enrollment_overview')
                ->where('enrollment_code', $oldEnrollment->code)
                ->value('enrollment_status'),
        );
    }

    public function test_creation_rejects_a_client_defined_activity_state(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['enrollments.manage']);
        $student = Student::factory()->create();
        [$group, $shifts] = $this->academicStructure($branch);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->post(route('enrollments.store', $student), [
                'academic_group_code' => $group->code,
                'shift_codes' => [$shifts->first()->code],
                'is_active' => false,
            ])
            ->assertSessionHasErrors('is_active');

        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_unfinished_inactive_enrollment_precedes_long_finalized_history(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['students.view']);
        $student = Student::factory()->create();
        [$currentGroup] = $this->academicStructure($branch);
        $currentEnrollment = Enrollment::factory()->create([
            'student_code' => $student->code,
            'academic_group_code' => $currentGroup->code,
            'roll_code' => '9000',
            'is_active' => false,
        ]);

        foreach (range(1, 11) as $index) {
            [$historicalGroup, , $historicalCycle] = $this->academicStructure($branch);
            Enrollment::factory()->create([
                'student_code' => $student->code,
                'academic_group_code' => $historicalGroup->code,
                'roll_code' => str_pad((string) $index, 4, '0', STR_PAD_LEFT),
            ]);
            $historicalCycle->update([
                'start_date' => CarbonImmutable::now('America/Lima')
                    ->subYears($index + 1)
                    ->toDateString(),
                'end_date' => CarbonImmutable::now('America/Lima')
                    ->subYears($index)
                    ->toDateString(),
            ]);
        }

        $directoryRow = DB::table('student_directory')
            ->where('student_code', $student->code)
            ->sole();
        $this->assertSame('9000', $directoryRow->roll_code);
        $this->assertSame('inactive', $directoryRow->enrollment_status);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('students.show', $student))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('enrollments.0.code', $currentEnrollment->code)
                ->where('enrollments.0.status', 'inactive')
                ->has('enrollments', 10));
    }

    public function test_new_enrollment_entry_redirects_to_the_existing_unfinished_record(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['enrollments.manage']);
        $student = Student::factory()->create();
        [$group] = $this->academicStructure($branch);
        $enrollment = Enrollment::factory()->create([
            'student_code' => $student->code,
            'academic_group_code' => $group->code,
            'is_active' => false,
        ]);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('enrollments.create', $student))
            ->assertRedirect(route('enrollments.edit', $enrollment));

        $this->assertSame(1, $student->enrollments()->count());
    }

    public function test_finished_enrollment_edit_is_read_only(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['enrollments.manage', 'students.view']);
        $student = Student::factory()->create();
        [$group, , $cycle] = $this->academicStructure($branch);
        $enrollment = Enrollment::factory()->create([
            'student_code' => $student->code,
            'academic_group_code' => $group->code,
        ]);
        $cycle->update([
            'start_date' => CarbonImmutable::now('America/Lima')->subYear()->toDateString(),
            'end_date' => CarbonImmutable::now('America/Lima')->subDay()->toDateString(),
        ]);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('enrollments.edit', $enrollment))
            ->assertRedirect(route('students.show', $student));
    }

    public function test_roster_is_branch_scoped_and_lists_only_active_section_enrollments(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['enrollments.view']);
        $student = Student::factory()->create([
            'dni' => '12344321',
            'first_name' => 'Lucía',
            'last_name' => 'Ramos',
        ]);
        [$group, $shifts, $cycle, $degree] = $this->academicStructure($branch);
        $enrollment = Enrollment::factory()->create([
            'student_code' => $student->code,
            'academic_group_code' => $group->code,
            'roll_code' => '0314',
            'is_active' => true,
        ]);
        $enrollment->shifts()->attach($shifts->first());
        Enrollment::factory()->create([
            'academic_group_code' => $group->code,
            'roll_code' => '0315',
            'is_active' => false,
        ]);

        [$foreignGroup] = $this->academicStructure();
        Enrollment::factory()->create([
            'academic_group_code' => $foreignGroup->code,
            'roll_code' => '9999',
        ]);

        $response = $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('enrollments.index', [
                'q' => 'Lucía',
                'cycle' => $cycle->code,
                'degree' => $degree->number,
                'group' => $group->code,
            ]))
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Students/Roster')
            ->has('enrollments.data', 1)
            ->where('enrollments.data.0.code', $enrollment->code)
            ->where('context_complete', true)
            ->where('filters.group', $group->code));
    }

    public function test_roster_does_not_query_enrollments_without_complete_context(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['enrollments.view']);
        [$group, , $cycle] = $this->academicStructure($branch);
        Enrollment::factory()->create(['academic_group_code' => $group->code]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('enrollments.index', ['cycle' => $cycle->code]))
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('context_complete', false)
            ->has('enrollments.data', 0));

        $this->assertFalse(collect(DB::getQueryLog())->contains(
            fn (array $query): bool => str_contains($query['query'], 'student_roster'),
        ));
    }

    public function test_roster_restores_the_last_valid_context_for_the_current_branch(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['enrollments.view']);
        [$group, , $cycle, $degree] = $this->academicStructure($branch);
        $context = [
            'cycle' => $cycle->code,
            'degree' => $degree->number,
            'group' => $group->code,
        ];

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('enrollments.index', $context))
            ->assertOk()
            ->assertSessionHas(
                "student_roster_contexts.{$branch->code}",
                $context,
            );

        $this->get(route('enrollments.index'))
            ->assertRedirect(route('enrollments.index', $context));
    }

    public function test_roster_discards_a_remembered_context_that_is_no_longer_active(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['enrollments.view']);
        $sessionKey = "student_roster_contexts.{$branch->code}";

        $this->actingAs($account)
            ->withSession([
                'current_branch_code' => $branch->code,
                'student_roster_contexts' => [
                    $branch->code => [
                        'cycle' => (string) Str::uuid(),
                        'degree' => 1,
                        'group' => (string) Str::uuid(),
                    ],
                ],
            ])
            ->get(route('enrollments.index'))
            ->assertOk()
            ->assertSessionMissing($sessionKey)
            ->assertInertia(fn (Assert $page) => $page
                ->where('context_complete', false)
                ->has('enrollments.data', 0));
    }

    public function test_roster_permission_can_read_photo_only_for_the_current_branch(): void
    {
        Storage::fake('local');
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['enrollments.view']);
        $student = Student::factory()->create([
            'photo_path' => 'student-photos/current.jpg',
        ]);
        Storage::disk('local')->put($student->photo_path, 'current');
        [$group] = $this->academicStructure($branch);
        Enrollment::factory()->create([
            'student_code' => $student->code,
            'academic_group_code' => $group->code,
        ]);

        $foreign = Student::factory()->create([
            'photo_path' => 'student-photos/foreign.jpg',
        ]);
        Storage::disk('local')->put($foreign->photo_path, 'foreign');
        [$foreignGroup] = $this->academicStructure();
        Enrollment::factory()->create([
            'student_code' => $foreign->code,
            'academic_group_code' => $foreignGroup->code,
            'roll_code' => '9998',
        ]);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('students.photo', $student))
            ->assertOk();

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('students.photo', $foreign))
            ->assertForbidden();
    }

    public function test_edit_keeps_inactive_historical_group_and_shift_visible(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['enrollments.manage']);
        [$group, $shifts, $cycle] = $this->academicStructure($branch);
        $enrollment = Enrollment::factory()->create([
            'academic_group_code' => $group->code,
            'is_active' => false,
        ]);
        $enrollment->shifts()->attach($shifts->first());
        $group->update(['is_active' => false]);
        $shifts->first()->update(['is_active' => false]);
        $cycle->update(['is_active' => false]);

        $response = $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('enrollments.edit', $enrollment))
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Students/EnrollmentForm')
            ->where('enrollment.academic_group_code', $group->code)
            ->where('enrollment.shift_codes.0', $shifts->first()->code)
            ->where('options.groups.0.code', $group->code)
            ->where('options.shifts.0.code', $shifts->first()->code));
    }

    public function test_enrollment_routes_require_semantic_permissions(): void
    {
        $account = $this->createEmployeeAccount();

        $this->actingAs($account)
            ->get(route('enrollments.index'))
            ->assertForbidden();
    }

    /**
     * @return array{AcademicGroup, Collection<int, CycleShift>, AcademicCycle, CycleDegree}
     */
    private function academicStructure(?Branch $branch = null): array
    {
        $branch ??= Branch::factory()->create();
        $cycle = AcademicCycle::factory()->create([
            'branch_code' => $branch->code,
            'is_active' => true,
        ]);
        $degree = CycleDegree::factory()->create(['cycle_code' => $cycle->code]);
        $group = AcademicGroup::factory()->create([
            'cycle_degree_code' => $degree->code,
            'is_active' => true,
        ]);
        $shifts = CycleShift::factory()->count(2)->create([
            'cycle_code' => $cycle->code,
            'is_active' => true,
        ]);

        return [$group, $shifts, $cycle, $degree];
    }
}
