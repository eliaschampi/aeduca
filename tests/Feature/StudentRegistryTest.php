<?php

namespace Tests\Feature;

use App\Models\AcademicCycle;
use App\Models\AcademicGroup;
use App\Models\Branch;
use App\Models\CycleDegree;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\StudentContact;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StudentRegistryTest extends TestCase
{
    public function test_directory_requires_student_view_permission(): void
    {
        $account = $this->createEmployeeAccount();

        $this->actingAs($account)
            ->get(route('students.search'))
            ->assertForbidden();
    }

    public function test_manager_creates_identity_then_uploads_private_photo_from_profile(): void
    {
        Storage::fake('local');
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['students.manage']);

        $response = $this->actingAs($account)
            ->post(route('students.store'), [
                'dni' => '12 345 678',
                'first_name' => '  Ana María  ',
                'last_name' => '  Torres  ',
                'birth_date' => '2014-05-20',
                'phone' => '',
                'address' => 'Av. Principal',
                'observation' => '',
                'is_active' => true,
            ]);

        $student = Student::query()->sole();
        $response->assertRedirect(route('students.show', $student));
        $this->assertSame('12345678', $student->dni);
        $this->assertSame('Ana María', $student->first_name);
        $this->assertNull($student->phone);
        $this->assertNull($student->photo_path);

        $this->actingAs($account)
            ->put(route('students.photo.update', $student), [
                'photo' => UploadedFile::fake()->image('ana.jpg', 640, 640),
            ])
            ->assertRedirect(route('students.show', $student));

        $photoPath = $student->fresh()->photo_path;
        $this->assertNotNull($photoPath);
        Storage::disk('local')->assertExists($photoPath);

        $this->actingAs($account)
            ->get(route('students.photo', $student))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, private');
    }

    public function test_replacing_photo_removes_the_previous_private_file(): void
    {
        Storage::fake('local');
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['students.manage']);
        $student = Student::factory()->create(['photo_path' => 'student-photos/old.jpg']);
        Storage::disk('local')->put($student->photo_path, 'old');

        $this->actingAs($account)
            ->put(route('students.photo.update', $student), [
                'photo' => UploadedFile::fake()->image('new.png', 400, 400),
            ])
            ->assertRedirect(route('students.show', $student));

        $newPath = $student->fresh()->photo_path;
        $this->assertNotSame('student-photos/old.jpg', $newPath);
        Storage::disk('local')->assertMissing('student-photos/old.jpg');
        Storage::disk('local')->assertExists($newPath);
    }

    public function test_photo_upload_rejects_an_image_that_did_not_pass_square_crop(): void
    {
        Storage::fake('local');
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['students.manage']);
        $student = Student::factory()->create();

        $this->actingAs($account)
            ->put(route('students.photo.update', $student), [
                'photo' => UploadedFile::fake()->image('wide.jpg', 640, 480),
            ])
            ->assertSessionHasErrors('photo');

        $this->assertNull($student->fresh()->photo_path);
    }

    public function test_dni_is_unique_and_invalid_shape_is_rejected(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['students.manage']);
        $student = Student::factory()->create(['dni' => '12345678']);

        $payload = [
            'first_name' => 'Ana',
            'last_name' => 'Torres',
            'is_active' => true,
        ];

        $this->actingAs($account)
            ->post(route('students.store'), [...$payload, 'dni' => $student->dni])
            ->assertSessionHasErrors('dni');

        $this->actingAs($account)
            ->post(route('students.store'), [...$payload, 'dni' => '1234A678'])
            ->assertSessionHasErrors('dni');
    }

    public function test_directory_searches_institutionally_by_dni_name_and_active_roll_code(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['students.view']);
        $exact = Student::factory()->create([
            'dni' => '87654321',
            'first_name' => 'María',
            'last_name' => 'Quispe',
        ]);
        Student::factory()->create([
            'dni' => '11112222',
            'first_name' => 'Mario',
            'last_name' => 'Quispe',
        ]);
        [$group] = $this->academicGroup();
        Enrollment::factory()->create([
            'student_code' => $exact->code,
            'academic_group_code' => $group->code,
            'roll_code' => '0042',
            'is_active' => true,
        ]);

        foreach (['87654321', 'María Quispe', '0042'] as $search) {
            $response = $this->actingAs($account)
                ->get(route('students.search', ['q' => $search]))
                ->assertOk();

            $rows = $response->inertiaProps('students.data');
            $this->assertSame($exact->code, $rows[0]['code']);
        }
    }

    public function test_shell_lookup_reuses_institutional_search_and_is_bounded(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['students.view']);
        Student::factory()->count(25)->create([
            'first_name' => 'Valeria',
        ]);

        $this->actingAs($account)
            ->getJson(route('students.lookup', ['q' => 'Valeria', 'limit' => 7]))
            ->assertOk()
            ->assertJsonCount(7, 'items')
            ->assertJsonStructure([
                'items' => [['code', 'full_name', 'dni', 'roll_code']],
            ]);
    }

    public function test_directory_query_count_stays_bounded_with_a_full_page(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['students.view']);
        Student::factory()->count(25)->create();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($account)
            ->get(route('students.search'))
            ->assertOk();

        $this->assertCount(15, $response->inertiaProps('students.data'));
        $this->assertLessThan(
            15,
            count(DB::getQueryLog()),
            'The directory must not query per student row.',
        );
    }

    public function test_profile_limits_employee_history_to_authorized_branches(): void
    {
        $account = $this->createEmployeeAccount();
        $authorizedBranch = $account->user->branches->sole();
        $this->grantPermissions($account, ['students.view']);
        $student = Student::factory()->create();
        [$ownGroup] = $this->academicGroup($authorizedBranch);
        [$foreignGroup] = $this->academicGroup();
        Enrollment::factory()->create([
            'student_code' => $student->code,
            'academic_group_code' => $ownGroup->code,
            'roll_code' => '0001',
            'is_active' => true,
        ]);
        Enrollment::factory()->create([
            'student_code' => $student->code,
            'academic_group_code' => $foreignGroup->code,
            'roll_code' => '0002',
            'is_active' => false,
        ]);

        $this->actingAs($account)
            ->get(route('students.show', $student))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Students/Show')
                ->has('enrollments', 1)
                ->where('enrollments.0.roll_code', '0001'));
    }

    public function test_contact_updates_cannot_cross_student_ownership(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['students.manage']);
        $student = Student::factory()->create();
        $contact = StudentContact::factory()->create();

        $this->actingAs($account)
            ->put(route('students.contacts.update', [$student, $contact]), [
                'name' => 'Cambio indebido',
                'phone' => null,
                'note' => null,
            ])
            ->assertNotFound();

        $this->assertNotSame('Cambio indebido', $contact->fresh()->name);
    }

    /**
     * @return array{AcademicGroup, Branch}
     */
    private function academicGroup(?Branch $branch = null): array
    {
        $branch ??= Branch::factory()->create();
        $cycle = AcademicCycle::factory()->create(['branch_code' => $branch->code]);
        $degree = CycleDegree::factory()->create(['cycle_code' => $cycle->code]);

        return [
            AcademicGroup::factory()->create(['cycle_degree_code' => $degree->code]),
            $branch,
        ];
    }
}
