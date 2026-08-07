<?php

namespace Tests\Feature;

use App\Models\AcademicCycle;
use App\Models\AcademicGroup;
use App\Models\AuthAccount;
use App\Models\Branch;
use App\Models\CycleDegree;
use App\Models\DriveFile;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\StudentAttention;
use App\Models\User;
use App\Support\Drive\DriveStorage;
use App\Support\StudentAttentions\StudentAttentionType;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentAttentionTest extends TestCase
{
    public function test_branch_index_and_creation_picker_are_scoped_to_current_branch(): void
    {
        $unauthorized = $this->createEmployeeAccount();
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $foreignBranch = Branch::factory()->create();
        $this->grantPermissions($account, ['student_attentions.manage']);
        $student = Student::factory()->create([
            'first_name' => 'Valeria',
            'last_name' => 'Quispe',
        ]);
        $foreignStudent = Student::factory()->create([
            'first_name' => 'Valeria',
            'last_name' => 'Ajena',
        ]);
        $this->enroll($student, $branch);
        $this->enroll($foreignStudent, $foreignBranch);
        $attention = $this->makeAttention($student, $branch, $account->user);
        $this->makeAttention($foreignStudent, $foreignBranch, $account->user);

        $this->actingAs($unauthorized)
            ->get(route('student-attentions.index'))
            ->assertForbidden();

        $this->actingAs($account)
            ->get(route('student-attentions.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Students/Attentions/BranchIndex')
                ->where('attentions.total', 1)
                ->where('attentions.data.0.code', $attention->code)
                ->where('attentions.data.0.student_code', $student->code)
                ->where('can_manage', true));

        $this->actingAs($account)
            ->getJson(route('student-attentions.students', ['q' => 'Valeria']))
            ->assertOk()
            ->assertJsonPath('items.0.code', $student->code)
            ->assertJsonMissing(['code' => $foreignStudent->code]);
    }

    public function test_manager_creates_and_updates_an_attention_with_server_owned_context(): void
    {
        $creator = $this->createEmployeeAccount();
        $branch = $creator->user->branches->sole();
        $editor = $this->employeeInBranch($branch);
        $this->grantPermissions($creator, ['student_attentions.manage']);
        $this->grantPermissions($editor, ['student_attentions.manage', 'drive.manage']);
        $student = Student::factory()->create(['is_active' => false]);
        $this->enroll($student, $branch, active: false);

        $response = $this->actingAs($creator)
            ->post(route('students.attentions.store', $student), $this->payload());

        $attention = StudentAttention::query()->sole();
        $response->assertRedirect(route('students.attentions.show', [$student, $attention]));
        $this->assertSame($student->code, $attention->student_code);
        $this->assertSame($branch->code, $attention->branch_code);
        $this->assertSame($creator->user->code, $attention->created_by_user_code);
        $this->assertNull($attention->updated_by_user_code);

        $this->actingAs($creator)
            ->post(route('students.attentions.store', $student), [
                ...$this->payload(),
                'occurred_at' => now()
                    ->setTimezone((string) config('aeduca.business_timezone'))
                    ->addDay()
                    ->format('Y-m-d\TH:i'),
            ])
            ->assertSessionHasErrors('occurred_at');
        $this->assertSame(1, StudentAttention::query()->count());

        $development = trim(str_repeat('Desarrollo documentado con detalle. ', 80));
        $conclusion = trim(str_repeat('Acuerdo institucional registrado. ', 25));

        $this->actingAs($editor)
            ->put(route('students.attentions.update', [$student, $attention]).'?attach=1', [
                ...$this->payload(),
                'reason' => 'Seguimiento actualizado',
                'development' => $development,
                'conclusion' => $conclusion,
            ])
            ->assertRedirect(route('students.attentions.show', [$student, $attention]))
            ->assertInertiaFlash('open_attachments', true);

        $attention->refresh();
        $this->assertSame('Seguimiento actualizado', $attention->reason);
        $this->assertSame($development, $attention->development);
        $this->assertSame($conclusion, $attention->conclusion);
        $this->assertSame($creator->user->code, $attention->created_by_user_code);
        $this->assertSame($editor->user->code, $attention->updated_by_user_code);
        $this->assertSame($branch->code, $attention->branch_code);
    }

    public function test_creation_requires_branch_enrollment_and_history_is_branch_scoped(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $foreignBranch = Branch::factory()->create();
        $this->grantPermissions($account, ['student_attentions.manage']);
        $student = Student::factory()->create();
        $this->enroll($student, $foreignBranch);

        $this->actingAs($account)
            ->post(route('students.attentions.store', $student), $this->payload())
            ->assertSessionHasErrors('student');

        $foreign = $this->makeAttention($student, $foreignBranch, $account->user);

        $this->actingAs($account)
            ->get(route('students.attentions.index', $student))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('attentions.total', 0)
                ->where('can_manage', false));

        $this->actingAs($account)
            ->get(route('students.attentions.show', [$student, $foreign]))
            ->assertNotFound();
    }

    public function test_historical_attention_remains_visible_after_identity_deactivation(): void
    {
        $creator = $this->createEmployeeAccount();
        $branch = $creator->user->branches->sole();
        $viewer = $this->employeeInBranch($branch);
        $this->grantPermissions($viewer, ['student_attentions.view']);
        $student = Student::factory()->create();
        $this->enroll($student, $branch);
        $attention = $this->makeAttention($student, $branch, $creator->user);

        $student->update(['is_active' => false]);
        $creator->user->update(['is_active' => false]);

        $this->actingAs($viewer)
            ->get(route('students.attentions.index', $student))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('attentions.total', 1)
                ->where('attentions.data.0.code', $attention->code)
                ->where('attentions.data.0.author_name', trim(
                    $creator->user->first_name.' '.$creator->user->last_name,
                )));
    }

    public function test_drive_link_preserves_trashed_history_and_blocks_permanent_deletion(): void
    {
        Storage::fake('local');
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $viewer = $this->employeeInBranch($branch);
        $this->grantPermissions($account, ['student_attentions.manage', 'drive.manage']);
        $this->grantPermissions($viewer, ['student_attentions.view']);
        $student = Student::factory()->create();
        $this->enroll($student, $branch);
        $attention = $this->makeAttention($student, $branch, $account->user);
        $file = $this->makeFile($account->user, 'informe.txt');
        Storage::disk('local')->put((string) $file->storage_path, 'contenido');

        $this->actingAs($account)
            ->postJson(route('students.attentions.files.store', [$student, $attention]), [
                'file_code' => $file->code,
            ])
            ->assertCreated();

        $this->actingAs($account)
            ->patchJson(route('drive.files.update', $file), ['trashed' => true])
            ->assertOk();

        $this->actingAs($account)
            ->get(route('drive.files.serve', $file))
            ->assertNotFound();
        $this->actingAs($viewer)
            ->get(route('students.attentions.files.serve', [$student, $attention, $file]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

        $this->actingAs($account)
            ->deleteJson(route('drive.files.destroy', $file))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
        $this->actingAs($account)
            ->deleteJson(route('drive.trash.destroy'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
        $this->assertDatabaseHas('drive_files', ['code' => $file->code]);

        $this->actingAs($account)
            ->deleteJson(route('students.attentions.files.destroy', [$student, $attention, $file]))
            ->assertOk();
        $this->actingAs($account)
            ->deleteJson(route('drive.files.destroy', $file))
            ->assertOk();

        $this->assertDatabaseMissing('drive_files', ['code' => $file->code]);
        Storage::disk('local')->assertMissing((string) $file->storage_path);
    }

    public function test_only_own_live_files_can_be_linked_and_student_self_has_no_access(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $other = $this->employeeInBranch($branch);
        $this->grantPermissions($account, ['student_attentions.manage', 'drive.manage']);
        $student = Student::factory()->create();
        $this->enroll($student, $branch);
        $attention = $this->makeAttention($student, $branch, $account->user);
        $foreignFile = $this->makeFile($other->user, 'ajeno.txt');

        $this->actingAs($other)
            ->get(route('students.attentions.index', $student))
            ->assertForbidden();

        $this->actingAs($account)
            ->postJson(route('students.attentions.files.store', [$student, $attention]), [
                'file_code' => $foreignFile->code,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file_code');

        $studentAccount = $this->createStudentAccount([], ['dni' => '91827364']);
        $this->actingAs($studentAccount)
            ->get(route('students.attentions.index', $studentAccount->student))
            ->assertForbidden();
    }

    public function test_attention_prevents_physical_student_deletion(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['students.delete']);
        $student = Student::factory()->create();
        $this->makeAttention($student, $branch, $account->user);

        $this->actingAs($account)
            ->delete(route('students.destroy', $student))
            ->assertSessionHasErrors('student');

        $this->assertDatabaseHas('students', ['code' => $student->code]);
    }

    /**
     * @return array<string, string>
     */
    private function payload(): array
    {
        return [
            'type' => StudentAttentionType::Attention->value,
            'reason' => 'Reunión de seguimiento',
            'development' => 'Se revisó la situación académica con el alumno.',
            'conclusion' => 'Se acordó realizar seguimiento durante la semana.',
            'occurred_at' => now()
                ->setTimezone((string) config('aeduca.business_timezone'))
                ->subHour()
                ->format('Y-m-d\TH:i'),
        ];
    }

    private function employeeInBranch(Branch $branch): AuthAccount
    {
        $account = $this->createEmployeeAccount(branchCount: 0);
        $account->user->branches()->attach($branch);

        return $account->load('user.employeeRole', 'user.branches');
    }

    private function enroll(Student $student, Branch $branch, bool $active = true): Enrollment
    {
        $cycle = AcademicCycle::factory()->create(['branch_code' => $branch->code]);
        $degree = CycleDegree::factory()->create(['cycle_code' => $cycle->code]);
        $group = AcademicGroup::factory()->create(['cycle_degree_code' => $degree->code]);

        return Enrollment::factory()->create([
            'student_code' => $student->code,
            'academic_group_code' => $group->code,
            'cycle_code' => $cycle->code,
            'is_active' => $active,
        ]);
    }

    private function makeAttention(
        Student $student,
        Branch $branch,
        User $creator,
    ): StudentAttention {
        $attention = new StudentAttention([
            'type' => StudentAttentionType::Attention,
            'reason' => 'Seguimiento',
            'development' => 'Desarrollo suficiente para la atención.',
            'conclusion' => 'Acuerdo registrado.',
            'occurred_at' => now()
                ->setTimezone((string) config('aeduca.business_timezone'))
                ->startOfMonth()
                ->utc(),
        ]);
        $attention->student_code = $student->code;
        $attention->branch_code = $branch->code;
        $attention->created_by_user_code = $creator->code;
        $attention->save();

        return $attention;
    }

    private function makeFile(User $owner, string $name): DriveFile
    {
        $file = new DriveFile([
            'name' => $name,
            'type' => 'doc',
            'size' => 9,
            'storage_path' => DriveStorage::DIRECTORY.'/'.$owner->code.'-'.$name,
            'mime_type' => 'text/plain',
        ]);
        $file->user_code = $owner->code;
        $file->save();

        return $file;
    }
}
