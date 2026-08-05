<?php

namespace Tests\Feature;

use App\Actions\SaveCycle;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AttendanceIntegrityTest extends TestCase
{
    public function test_removing_a_referenced_shift_returns_validation_and_rolls_back(): void
    {
        $branch = Branch::factory()->create();
        $cycle = AcademicCycle::factory()->create(['branch_code' => $branch->code]);
        $shiftA = CycleShift::factory()->create([
            'cycle_code' => $cycle->code,
            'name' => 'Mañana',
            'entry_time' => '07:00:00',
            'tolerance_minutes' => 30,
            'sort_order' => 0,
        ]);
        $shiftB = CycleShift::factory()->create([
            'cycle_code' => $cycle->code,
            'name' => 'Tarde',
            'entry_time' => '13:00:00',
            'tolerance_minutes' => 30,
            'sort_order' => 1,
        ]);
        $group = AcademicGroup::factory()->create([
            'cycle_degree_code' => CycleDegree::factory()->create(['cycle_code' => $cycle->code])->code,
        ]);
        $enrollment = Enrollment::factory()->create(['academic_group_code' => $group->code]);
        $enrollment->shifts()->attach($shiftA->code);

        try {
            app(SaveCycle::class)->handle(
                $branch,
                $cycle,
                $this->cycleAttributes($cycle),
                [
                    [
                        'code' => $shiftB->code,
                        'name' => $shiftB->name,
                        'entry_time' => '13:00',
                        'tolerance_minutes' => 30,
                    ],
                ],
                $this->degreesPayload($cycle),
            );
            $this->fail('Expected removing an assigned shift to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('shifts', $exception->errors());
        }

        $this->assertDatabaseHas('cycle_shifts', ['code' => $shiftA->code]);
        $this->assertDatabaseHas('enrollment_shifts', [
            'enrollment_code' => $enrollment->code,
            'cycle_shift_code' => $shiftA->code,
        ]);
    }

    public function test_removing_a_referenced_group_returns_validation_and_rolls_back(): void
    {
        $branch = Branch::factory()->create();
        $cycle = AcademicCycle::factory()->create([
            'branch_code' => $branch->code,
            'name' => 'Ciclo con sección usada',
        ]);
        $degree = CycleDegree::factory()->create(['cycle_code' => $cycle->code, 'number' => 1]);
        $group = AcademicGroup::factory()->create([
            'cycle_degree_code' => $degree->code,
            'name' => 'A',
        ]);
        CycleShift::factory()->create(['cycle_code' => $cycle->code]);
        Enrollment::factory()->create(['academic_group_code' => $group->code]);

        try {
            app(SaveCycle::class)->handle(
                $branch,
                $cycle,
                $this->cycleAttributes($cycle),
                $this->shiftsPayload($cycle),
                [
                    [
                        'number' => 1,
                        'groups' => [['name' => 'B']],
                    ],
                ],
            );
            $this->fail('Expected removing a group with enrollments to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('degrees', $exception->errors());
        }

        $this->assertDatabaseHas('academic_groups', ['code' => $group->code, 'name' => 'A']);
        $this->assertSame('Ciclo con sección usada', $cycle->fresh()->name);
    }

    public function test_unused_academic_structure_can_still_be_removed(): void
    {
        $branch = Branch::factory()->create();
        $cycle = AcademicCycle::factory()->create([
            'branch_code' => $branch->code,
            'name' => 'Ciclo limpio',
        ]);
        $degree = CycleDegree::factory()->create(['cycle_code' => $cycle->code, 'number' => 1]);
        $group = AcademicGroup::factory()->create([
            'cycle_degree_code' => $degree->code,
            'name' => 'A',
        ]);
        $shift = CycleShift::factory()->create([
            'cycle_code' => $cycle->code,
            'name' => 'Mañana',
            'entry_time' => '07:00:00',
            'tolerance_minutes' => 20,
        ]);

        app(SaveCycle::class)->handle(
            $branch,
            $cycle,
            [
                ...$this->cycleAttributes($cycle),
                'name' => 'Ciclo limpio editado',
            ],
            [
                [
                    'code' => $shift->code,
                    'name' => 'Mañana',
                    'entry_time' => '07:00',
                    'tolerance_minutes' => 20,
                ],
            ],
            [
                [
                    'number' => 1,
                    'groups' => [
                        [
                            'code' => $group->code,
                            'name' => 'A',
                        ],
                    ],
                ],
                [
                    'number' => 2,
                    'groups' => [['name' => 'Nueva']],
                ],
            ],
        );

        $this->assertSame('Ciclo limpio editado', $cycle->fresh()->name);
        $this->assertTrue($cycle->degrees()->where('number', 2)->exists());
    }

    public function test_attendance_sensitive_cycle_fields_freeze_after_facts_exist(): void
    {
        $branch = Branch::factory()->create();
        $cycle = AcademicCycle::factory()->create([
            'branch_code' => $branch->code,
            'start_date' => '2026-03-01',
            'end_date' => '2026-06-30',
            'attendance_includes_saturday' => false,
            'name' => 'Ciclo con hechos',
        ]);
        $degree = CycleDegree::factory()->create(['cycle_code' => $cycle->code, 'number' => 1]);
        $group = AcademicGroup::factory()->create(['cycle_degree_code' => $degree->code, 'name' => 'A']);
        $shift = CycleShift::factory()->create([
            'cycle_code' => $cycle->code,
            'name' => 'Mañana',
            'entry_time' => '07:00:00',
            'tolerance_minutes' => 30,
        ]);
        $enrollment = Enrollment::factory()->create(['academic_group_code' => $group->code]);
        $enrollment->shifts()->attach($shift->code);
        $this->seedFact($enrollment, $shift, '2026-03-10');

        try {
            app(SaveCycle::class)->handle(
                $branch,
                $cycle,
                [
                    ...$this->cycleAttributes($cycle),
                    'start_date' => '2026-03-15',
                    'attendance_includes_saturday' => true,
                    'end_date' => '2026-05-01',
                ],
                $this->shiftsPayload($cycle),
                $this->degreesPayload($cycle),
            );
            $this->fail('Expected attendance-sensitive freezes.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('start_date', $exception->errors());
            $this->assertArrayHasKey('attendance_includes_saturday', $exception->errors());
            $this->assertArrayHasKey('end_date', $exception->errors());
        }

        app(SaveCycle::class)->handle(
            $branch,
            $cycle,
            [
                ...$this->cycleAttributes($cycle),
                'name' => 'Ciclo renombrado',
                'end_date' => '2026-07-31',
            ],
            [
                [
                    'code' => $shift->code,
                    'name' => 'Mañana',
                    'entry_time' => '07:00',
                    'tolerance_minutes' => 30,
                ],
            ],
            [
                [
                    'number' => 1,
                    'groups' => [
                        ['code' => $group->code, 'name' => 'A'],
                    ],
                ],
            ],
        );

        $cycle->refresh();
        $this->assertSame('Ciclo renombrado', $cycle->name);
        $this->assertSame('2026-03-01', $cycle->start_date->toDateString());
        $this->assertSame('2026-07-31', $cycle->end_date->toDateString());
        $this->assertFalse((bool) $cycle->attendance_includes_saturday);
    }

    public function test_shift_clock_cannot_change_after_facts_exist(): void
    {
        $branch = Branch::factory()->create();
        $cycle = AcademicCycle::factory()->create(['branch_code' => $branch->code]);
        $degree = CycleDegree::factory()->create(['cycle_code' => $cycle->code]);
        $group = AcademicGroup::factory()->create(['cycle_degree_code' => $degree->code]);
        $shift = CycleShift::factory()->create([
            'cycle_code' => $cycle->code,
            'entry_time' => '07:00:00',
            'tolerance_minutes' => 30,
        ]);
        $enrollment = Enrollment::factory()->create(['academic_group_code' => $group->code]);
        $enrollment->shifts()->attach($shift->code);
        // The cycle factory picks any start date; attendance only exists Monday to Saturday.
        $factDate = $cycle->start_date->isSunday()
            ? $cycle->start_date->addDay()
            : $cycle->start_date;
        $this->seedFact($enrollment, $shift, $factDate->toDateString());

        try {
            app(SaveCycle::class)->handle(
                $branch,
                $cycle,
                $this->cycleAttributes($cycle),
                [
                    [
                        'code' => $shift->code,
                        'name' => $shift->name,
                        'entry_time' => '08:00',
                        'tolerance_minutes' => 45,
                    ],
                ],
                $this->degreesPayload($cycle),
            );
            $this->fail('Expected frozen shift clocks.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }

        $shift->refresh();
        $this->assertSame('07:00:00', CarbonImmutable::parse((string) $shift->entry_time)->format('H:i:s'));
        $this->assertSame(30, (int) $shift->tolerance_minutes);
    }

    public function test_history_does_not_generate_dates_before_attendance_starts_on(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-20 12:00:00', 'America/Lima'));

        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['attendance.view']);
        $student = Student::factory()->create();
        $cycle = AcademicCycle::factory()->create([
            'branch_code' => $branch->code,
            'start_date' => '2026-03-01',
            'end_date' => '2026-12-15',
            'is_active' => true,
        ]);
        $degree = CycleDegree::factory()->create(['cycle_code' => $cycle->code]);
        $group = AcademicGroup::factory()->create(['cycle_degree_code' => $degree->code]);
        $shift = CycleShift::factory()->create(['cycle_code' => $cycle->code, 'is_active' => true]);
        $enrollment = Enrollment::factory()->create([
            'student_code' => $student->code,
            'academic_group_code' => $group->code,
            'attendance_starts_on' => '2026-05-01',
        ]);
        $enrollment->shifts()->attach($shift->code);

        $this->actingAs($account)
            ->withSession(['current_branch_code' => $branch->code])
            ->get(route('students.attendance', [
                'student' => $student,
                'enrollment' => $enrollment->code,
                'shift' => $shift->code,
                'from' => '2026-03-01',
                'to' => '2026-05-10',
            ]))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $page->component('Attendance/History')
                    ->has('history')
                    ->where('history', function (mixed $history): bool {
                        $dates = collect($history)->pluck('attendance_date');

                        return $dates->isNotEmpty()
                            && $dates->every(fn (string $date): bool => $date >= '2026-05-01');
                    });
            });

        CarbonImmutable::setTestNow();
    }

    public function test_invalid_attendance_starts_on_is_rejected(): void
    {
        $branch = Branch::factory()->create();
        $student = Student::factory()->create();
        $cycle = AcademicCycle::factory()->create([
            'branch_code' => $branch->code,
            'start_date' => '2026-03-01',
            'end_date' => '2026-12-31',
            'is_active' => true,
        ]);
        $group = AcademicGroup::factory()->create([
            'cycle_degree_code' => CycleDegree::factory()->create(['cycle_code' => $cycle->code])->code,
            'is_active' => true,
        ]);
        $shift = CycleShift::factory()->create(['cycle_code' => $cycle->code, 'is_active' => true]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 12:00:00', 'America/Lima'));

        try {
            app(SaveEnrollment::class)->handle(
                $branch,
                $student,
                null,
                $group->code,
                [$shift->code],
                true,
                null,
                '2026-01-01',
            );
            $this->fail('Expected attendance start outside cycle to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('attendance_starts_on', $exception->errors());
        }

        CarbonImmutable::setTestNow();
    }

    public function test_cross_cycle_group_and_shift_corruption_is_detectable(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 12:00:00', 'America/Lima'));

        $branch = Branch::factory()->create();
        $cycleA = AcademicCycle::factory()->create([
            'branch_code' => $branch->code,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'is_active' => true,
        ]);
        $cycleB = AcademicCycle::factory()->create([
            'branch_code' => $branch->code,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'is_active' => true,
        ]);
        $groupB = AcademicGroup::factory()->create([
            'cycle_degree_code' => CycleDegree::factory()->create(['cycle_code' => $cycleB->code])->code,
            'is_active' => true,
        ]);
        $shiftB = CycleShift::factory()->create(['cycle_code' => $cycleB->code, 'is_active' => true]);
        $student = Student::factory()->create();

        $enrollmentCode = (string) Str::uuid();
        DB::table('enrollments')->insert([
            'code' => $enrollmentCode,
            'student_code' => $student->code,
            'cycle_code' => $cycleA->code,
            'academic_group_code' => $groupB->code,
            'roll_code' => '4321',
            'attendance_starts_on' => $cycleA->start_date->toDateString(),
            'is_active' => true,
            'observation' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('enrollment_shifts')->insert([
            'enrollment_code' => $enrollmentCode,
            'cycle_shift_code' => $shiftB->code,
        ]);

        $crossGroup = DB::table('enrollments as e')
            ->join('academic_groups as g', 'g.code', '=', 'e.academic_group_code')
            ->join('cycle_degrees as d', 'd.code', '=', 'g.cycle_degree_code')
            ->whereColumn('d.cycle_code', '<>', 'e.cycle_code')
            ->where('e.code', $enrollmentCode)
            ->exists();

        $crossShift = DB::table('enrollment_shifts as es')
            ->join('enrollments as e', 'e.code', '=', 'es.enrollment_code')
            ->join('cycle_shifts as cs', 'cs.code', '=', 'es.cycle_shift_code')
            ->whereColumn('cs.cycle_code', '<>', 'e.cycle_code')
            ->where('e.code', $enrollmentCode)
            ->exists();

        $this->assertTrue($crossGroup);
        $this->assertTrue($crossShift);

        $student2 = Student::factory()->create();
        app(SaveEnrollment::class)->handle(
            $branch,
            $student2,
            null,
            $groupB->code,
            [$shiftB->code],
            true,
            null,
            $cycleB->start_date->toDateString(),
        );

        $groupA = AcademicGroup::factory()->create([
            'cycle_degree_code' => CycleDegree::factory()->create(['cycle_code' => $cycleA->code])->code,
            'is_active' => true,
        ]);
        $student3 = Student::factory()->create();

        try {
            app(SaveEnrollment::class)->handle(
                $branch,
                $student3,
                null,
                $groupA->code,
                [$shiftB->code],
                true,
                null,
                $cycleA->start_date->toDateString(),
            );
            $this->fail('Cross-cycle shift assignment must be rejected by the application.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('shift_codes', $exception->errors());
        }

        CarbonImmutable::setTestNow();
    }

    /**
     * @return array{name: string, modality: string, start_date: string, end_date: string, attendance_includes_saturday: bool, is_active: bool}
     */
    private function cycleAttributes(AcademicCycle $cycle): array
    {
        return [
            'name' => $cycle->name,
            'modality' => $cycle->modality->value,
            'start_date' => $cycle->start_date->toDateString(),
            'end_date' => $cycle->end_date->toDateString(),
            'attendance_includes_saturday' => (bool) $cycle->attendance_includes_saturday,
            'is_active' => (bool) $cycle->is_active,
        ];
    }

    /**
     * @return list<array{code: string, name: string, entry_time: string, tolerance_minutes: int}>
     */
    private function shiftsPayload(AcademicCycle $cycle): array
    {
        return $cycle->shifts()->orderBy('sort_order')->get()->map(fn (CycleShift $shift): array => [
            'code' => $shift->code,
            'name' => $shift->name,
            'entry_time' => CarbonImmutable::parse((string) $shift->entry_time)->format('H:i'),
            'tolerance_minutes' => (int) $shift->tolerance_minutes,
        ])->all();
    }

    /**
     * @return list<array{number: int, groups: list<array{code?: string, name: string}>}>
     */
    private function degreesPayload(AcademicCycle $cycle): array
    {
        return $cycle->degrees()->with('groups')->orderBy('number')->get()->map(fn (CycleDegree $degree): array => [
            'number' => $degree->number,
            'groups' => $degree->groups->map(fn (AcademicGroup $group): array => [
                'code' => $group->code,
                'name' => $group->name,
            ])->values()->all(),
        ])->all();
    }

    private function seedFact(Enrollment $enrollment, CycleShift $shift, string $date): void
    {
        $account = $this->createEmployeeAccount();

        StudentAttendance::query()->create([
            'enrollment_code' => $enrollment->code,
            'cycle_shift_code' => $shift->code,
            'attendance_date' => $date,
            'state' => AttendanceState::Present,
            'arrival_at' => CarbonImmutable::parse("{$date} 07:00:00", 'America/Lima'),
            'recording_method' => AttendanceMethod::Manual,
            'created_by_user_code' => $account->user->code,
        ]);
    }
}
