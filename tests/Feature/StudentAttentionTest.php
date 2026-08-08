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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentAttentionTest extends TestCase
{
    public function test_month_list_and_student_picker_are_scoped_to_current_branch(): void
    {
        $unauthorized = $this->createEmployeeAccount();
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $foreignBranch = Branch::factory()->create();
        $this->grantPermissions($account, ['attentions.manage']);
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
        $visible = $this->makeAttention($student, $branch, $account->user);
        $this->makeAttention($foreignStudent, $foreignBranch, $account->user);
        $this->makeAttention(
            $student,
            $branch,
            $account->user,
            now()->subMonthNoOverflow()->startOfMonth()->utc(),
        );

        $this->actingAs($unauthorized)
            ->get(route('student-attentions.index'))
            ->assertForbidden();

        $this->actingAs($account)
            ->get(route('student-attentions.index', ['month' => now()->format('Y-m')]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('StudentAttentions/Index')
                ->where('filters.month', now()->format('Y-m'))
                ->where('attentions.total', 1)
                ->where('attentions.data.0.code', $visible->code)
                ->where('attentions.data.0.student_code', $student->code)
                ->where('can_manage', true));

        $this->actingAs($account)
            ->getJson(route('student-attentions.students', ['q' => 'Valeria']))
            ->assertOk()
            ->assertJsonPath('items.0.code', $student->code)
            ->assertJsonMissing(['code' => $foreignStudent->code]);
    }

    public function test_manager_creates_updates_and_deletes_an_attention_with_server_owned_context(): void
    {
        $creator = $this->createEmployeeAccount();
        $branch = $creator->user->branches->sole();
        $editor = $this->employeeInBranch($branch);
        $this->grantPermissions($creator, ['attentions.manage']);
        $this->grantPermissions($editor, ['attentions.manage']);
        $student = Student::factory()->create(['is_active' => false]);
        $otherStudent = Student::factory()->create();
        $this->enroll($student, $branch, active: false);
        $this->enroll($otherStudent, $branch);

        $response = $this->actingAs($creator)
            ->post(route('student-attentions.store'), $this->payload($student));

        $attention = StudentAttention::query()->sole();
        $response->assertRedirect(route('student-attentions.index', [
            'month' => $attention->occurred_at
                ->setTimezone((string) config('aeduca.business_timezone'))
                ->format('Y-m'),
        ]));
        $this->assertSame($student->code, $attention->student_code);
        $this->assertSame($branch->code, $attention->branch_code);
        $this->assertSame($creator->user->code, $attention->created_by_user_code);
        $this->assertNull($attention->updated_by_user_code);

        $this->actingAs($creator)
            ->post(route('student-attentions.store'), [
                ...$this->payload($student),
                'occurred_at' => now()
                    ->setTimezone((string) config('aeduca.business_timezone'))
                    ->addDay()
                    ->format('Y-m-d\TH:i'),
            ])
            ->assertSessionHasErrors('occurred_at');

        $this->actingAs($editor)
            ->put(route('student-attentions.update', $attention), [
                ...$this->payload($student),
                'reason' => 'Seguimiento actualizado',
            ])
            ->assertRedirect();

        $attention->refresh();
        $this->assertSame('Seguimiento actualizado', $attention->reason);
        $this->assertSame($creator->user->code, $attention->created_by_user_code);
        $this->assertSame($editor->user->code, $attention->updated_by_user_code);

        $this->actingAs($editor)
            ->put(route('student-attentions.update', $attention), $this->payload($otherStudent))
            ->assertNotFound();

        $this->actingAs($editor)
            ->delete(route('student-attentions.destroy', [
                'attention' => $attention,
                'month' => now()->format('Y-m'),
            ]))
            ->assertRedirect(route('student-attentions.index', ['month' => now()->format('Y-m')]));

        $this->assertDatabaseMissing('student_attentions', ['code' => $attention->code]);
    }

    public function test_creation_requires_branch_enrollment_and_history_is_branch_scoped(): void
    {
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $foreignBranch = Branch::factory()->create();
        $this->grantPermissions($account, ['attentions.manage']);
        $student = Student::factory()->create();
        $this->enroll($student, $foreignBranch);

        $this->actingAs($account)
            ->post(route('student-attentions.store'), $this->payload($student))
            ->assertSessionHasErrors('student_code');

        $foreign = $this->makeAttention($student, $foreignBranch, $account->user);

        $this->actingAs($account)
            ->get(route('student-attentions.edit', $foreign))
            ->assertNotFound();
        $this->actingAs($account)
            ->getJson(route('student-attentions.certificate', $foreign))
            ->assertNotFound();
    }

    public function test_attention_keeps_exactly_one_owned_drive_attachment(): void
    {
        Storage::fake('local');
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $other = $this->employeeInBranch($branch);
        $viewer = $this->employeeInBranch($branch);
        $this->grantPermissions($account, ['attentions.manage', 'drive.manage']);
        $this->grantPermissions($viewer, ['attentions.view']);
        $student = Student::factory()->create();
        $this->enroll($student, $branch);
        $first = $this->makeFile($account->user, 'primero.txt');
        $second = $this->makeFile($account->user, 'segundo.txt');
        $foreign = $this->makeFile($other->user, 'ajeno.txt');

        $this->actingAs($account)
            ->post(route('student-attentions.store'), [
                ...$this->payload($student),
                'drive_file_code' => $first->code,
            ])
            ->assertRedirect();

        $attention = StudentAttention::query()->sole();
        $this->assertSame($first->code, $attention->drive_file_code);

        $this->actingAs($account)
            ->put(route('student-attentions.update', $attention), [
                ...$this->payload($student),
                'drive_file_code' => $foreign->code,
            ])
            ->assertSessionHasErrors('drive_file_code');
        $this->assertSame($first->code, $attention->fresh()->drive_file_code);

        $this->actingAs($account)
            ->put(route('student-attentions.update', $attention), [
                ...$this->payload($student),
                'drive_file_code' => $second->code,
            ])
            ->assertRedirect();
        $this->assertSame($second->code, $attention->fresh()->drive_file_code);

        $this->actingAs($account)
            ->patchJson(route('drive.files.update', $second), ['trashed' => true])
            ->assertOk();
        $this->actingAs($account)
            ->get(route('drive.files.serve', $second))
            ->assertNotFound();
        $this->actingAs($viewer)
            ->get(route('student-attentions.attachment.show', $attention))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

        $this->actingAs($account)
            ->deleteJson(route('drive.files.destroy', $second))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->actingAs($account)
            ->delete(route('student-attentions.destroy', $attention))
            ->assertRedirect();
        $this->actingAs($account)
            ->deleteJson(route('drive.files.destroy', $second))
            ->assertOk();

        $this->assertDatabaseHas('drive_files', ['code' => $first->code]);
        $this->assertDatabaseMissing('drive_files', ['code' => $second->code]);
    }

    public function test_direct_upload_is_stored_in_the_drive_attentions_folder(): void
    {
        Storage::fake('local');
        $account = $this->createEmployeeAccount();
        $branch = $account->user->branches->sole();
        $this->grantPermissions($account, ['attentions.manage', 'drive.manage']);

        $response = $this->actingAs($account)->post(
            route('student-attentions.attachment.store'),
            ['file' => UploadedFile::fake()->create('informe.pdf', 128, 'application/pdf')],
            ['Accept' => 'application/json'],
        );

        $response->assertCreated();
        $folder = DriveFile::query()
            ->where('user_code', $account->user->code)
            ->whereNull('parent_code')
            ->where('name', 'Atenciones')
            ->sole();
        $file = DriveFile::query()
            ->where('parent_code', $folder->code)
            ->where('name', 'informe.pdf')
            ->sole();

        $this->assertSame('dir', $folder->type);
        $this->assertSame($file->code, $response->json('attachment.code'));
        Storage::disk('local')->assertExists((string) $file->storage_path);

        $student = Student::factory()->create();
        $this->enroll($student, $branch);
        $this->actingAs($account)
            ->post(route('student-attentions.store'), [
                ...$this->payload($student),
                'drive_file_code' => $file->code,
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('student_attentions', ['drive_file_code' => $file->code]);
    }

    public function test_certificate_is_authorized_and_student_self_service_is_excluded(): void
    {
        $creator = $this->createEmployeeAccount();
        $branch = $creator->user->branches->sole();
        $viewer = $this->employeeInBranch($branch);
        $this->grantPermissions($viewer, ['attentions.view']);
        $student = Student::factory()->create();
        $this->enroll($student, $branch);
        $attention = $this->makeAttention($student, $branch, $creator->user);

        $this->actingAs($viewer)
            ->getJson(route('student-attentions.certificate', $attention))
            ->assertOk()
            ->assertJsonPath('student.code', $student->code)
            ->assertJsonPath('attention.reason', $attention->reason)
            ->assertJsonPath('branch.name', $branch->name)
            ->assertJsonPath('author.full_name', trim(
                $creator->user->first_name.' '.$creator->user->last_name,
            ));

        $studentAccount = $this->createStudentAccount([], ['dni' => '91827364']);
        $this->actingAs($studentAccount)
            ->get(route('student-attentions.index'))
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
     * @return array<string, string|null>
     */
    private function payload(Student $student): array
    {
        return [
            'student_code' => $student->code,
            'type' => StudentAttentionType::Attention->value,
            'reason' => 'Reunión de seguimiento',
            'development' => 'Se revisó la situación académica con el alumno.',
            'conclusion' => 'Se acordó realizar seguimiento durante la semana.',
            'occurred_at' => now()
                ->setTimezone((string) config('aeduca.business_timezone'))
                ->subHour()
                ->format('Y-m-d\TH:i'),
            'drive_file_code' => null,
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
        mixed $occurredAt = null,
    ): StudentAttention {
        $attention = new StudentAttention([
            'type' => StudentAttentionType::Attention,
            'reason' => 'Seguimiento',
            'development' => 'Desarrollo suficiente para la atención.',
            'conclusion' => 'Acuerdo registrado.',
            'occurred_at' => $occurredAt ?? now()->subHour()->utc(),
        ]);
        $attention->student_code = $student->code;
        $attention->branch_code = $branch->code;
        $attention->created_by_user_code = $creator->code;
        $attention->save();

        return $attention;
    }

    private function makeFile(User $owner, string $name): DriveFile
    {
        $path = DriveStorage::DIRECTORY.'/'.$owner->code.'-'.$name;
        Storage::disk('local')->put($path, 'contenido');
        $file = new DriveFile([
            'name' => $name,
            'type' => 'doc',
            'size' => 9,
            'storage_path' => $path,
            'mime_type' => 'text/plain',
        ]);
        $file->user_code = $owner->code;
        $file->save();

        return $file;
    }
}
