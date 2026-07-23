<?php

namespace Tests\Feature;

use App\Actions\SaveStudent;
use App\Models\Student;
use App\Models\StudentContact;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StudentManagementTest extends TestCase
{
    public function test_student_routes_enforce_view_and_manage_permissions(): void
    {
        $student = Student::factory()->create();
        $unauthorized = $this->createEmployeeAccount();

        $this->actingAs($unauthorized)
            ->get(route('students.index'))
            ->assertForbidden();

        $viewer = $this->createEmployeeAccount();
        $this->grantPermissions($viewer, ['students.view']);

        $this->actingAs($viewer)
            ->get(route('students.show', $student))
            ->assertOk();
        $this->actingAs($viewer)
            ->get(route('students.create'))
            ->assertForbidden();
        $this->actingAs($viewer)
            ->put(route('students.update', $student), [])
            ->assertForbidden();

        $manager = $this->createEmployeeAccount();
        $this->grantPermissions($manager, ['students.manage']);

        $this->actingAs($manager)
            ->get(route('students.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can_manage', true));
        $this->actingAs($manager)
            ->get(route('students.create'))
            ->assertOk();
    }

    public function test_student_schema_uses_uuid_identity_without_branch_or_legacy_location_columns(): void
    {
        $student = Student::factory()->create();
        $contact = StudentContact::factory()->create(['student_code' => $student->code]);

        $this->assertTrue(Str::isUuid($student->code));
        $this->assertTrue(Str::isUuid($contact->code));
        $this->assertFalse(Schema::hasColumn('students', 'branch_code'));
        $this->assertFalse(Schema::hasColumn('students', 'legacy_id'));
        $this->assertFalse(Schema::hasColumn('students', 'ubigeo'));
        $this->assertFalse(Schema::hasColumn('students', 'district'));
    }

    public function test_create_normalizes_student_fields_and_redirects_to_profile(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['students.manage']);

        $response = $this->actingAs($account)
            ->post(route('students.store'), $this->validPayload([
                'dni' => ' 71234567 ',
                'first_name' => '  María Elena ',
                'last_name' => ' Quispe Mamani  ',
                'phone' => '   ',
                'address' => '',
                'observation' => '  ',
            ]));

        $student = Student::query()->sole();

        $response
            ->assertRedirect(route('students.show', $student))
            ->assertInertiaFlash('success', 'Estudiante creado');

        $this->assertSame('71234567', $student->dni);
        $this->assertSame('María Elena', $student->first_name);
        $this->assertSame('Quispe Mamani', $student->last_name);
        $this->assertNull($student->phone);
        $this->assertNull($student->address);
        $this->assertNull($student->observation);
        $this->assertSame(0, $student->contacts()->count());
    }

    public function test_validation_rejects_invalid_or_duplicate_dni(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['students.manage']);
        Student::factory()->create(['dni' => '71234567']);

        $this->actingAs($account)
            ->post(route('students.store'), $this->validPayload([
                'dni' => '1234',
            ]))
            ->assertSessionHasErrors('dni');

        $this->actingAs($account)
            ->post(route('students.store'), $this->validPayload(['dni' => '71234567']))
            ->assertSessionHasErrors('dni');
    }

    public function test_database_enforces_dni_and_contact_position_invariants(): void
    {
        $student = Student::factory()->create(['dni' => '71234567']);

        $this->assertQueryFails(fn () => DB::table('students')->insert([
            'code' => Str::uuid(),
            'dni' => 'ABC12345',
            'first_name' => 'Nombre',
            'last_name' => 'Apellido',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        StudentContact::factory()->create([
            'student_code' => $student->code,
            'position' => 1,
        ]);

        $this->assertQueryFails(fn () => StudentContact::factory()->create([
            'student_code' => $student->code,
            'position' => 1,
        ]));
        StudentContact::factory()->create([
            'student_code' => $student->code,
            'position' => 3,
        ]);
        $this->assertQueryFails(fn () => StudentContact::factory()->create([
            'student_code' => $student->code,
            'position' => 0,
        ]));
    }

    public function test_save_action_maps_a_concurrent_dni_conflict_to_the_dni_field(): void
    {
        Student::factory()->create(['dni' => '71234567']);

        try {
            app(SaveStudent::class)->handle(
                null,
                $this->studentAttributes(['dni' => '71234567']),
            );

            $this->fail('Expected the duplicate DNI to be mapped to validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dni', $exception->errors());
        }
    }

    public function test_student_update_does_not_replace_profile_contacts(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['students.manage']);
        $student = Student::factory()->create([
            'dni' => '71234567',
            'first_name' => 'Nombre anterior',
        ]);
        StudentContact::factory()->create([
            'student_code' => $student->code,
            'position' => 1,
            'name' => 'Contacto anterior',
        ]);

        $response = $this->actingAs($account)
            ->put(route('students.update', $student), $this->validPayload([
                'first_name' => 'Nombre actualizado',
            ]));

        $response
            ->assertRedirect(route('students.show', $student))
            ->assertInertiaFlash('success', 'Estudiante actualizado');

        $student->refresh();
        $this->assertSame('Nombre actualizado', $student->first_name);
        $this->assertSame(1, $student->contacts()->count());
        $this->assertSame('Contacto anterior', $student->contacts()->sole()->name);
        $this->assertSame(1, $student->contacts()->sole()->position);
    }

    public function test_profile_contact_crud_is_normalized_ordered_and_not_limited_to_two(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['students.manage']);
        $student = Student::factory()->create();
        StudentContact::factory()->create([
            'student_code' => $student->code,
            'position' => 1,
        ]);
        StudentContact::factory()->create([
            'student_code' => $student->code,
            'position' => 2,
        ]);

        $this->actingAs($account)
            ->post(route('students.contacts.store', $student), [
                'name' => '  Rosa Mamani  ',
                'phone' => ' 987 654 321 ',
                'note' => ' Madre ',
            ])
            ->assertRedirect(route('students.show', $student))
            ->assertInertiaFlash('success', 'Contacto agregado');

        $contact = $student->contacts()->where('position', 3)->sole();
        $this->assertSame('Rosa Mamani', $contact->name);
        $this->assertSame('987 654 321', $contact->phone);
        $this->assertSame('Madre', $contact->note);

        $this->actingAs($account)
            ->put(route('students.contacts.update', [$student, $contact]), [
                'name' => ' Rosa actualizada ',
                'phone' => '',
                'note' => ' ',
            ])
            ->assertRedirect(route('students.show', $student))
            ->assertInertiaFlash('success', 'Contacto actualizado');

        $contact->refresh();
        $this->assertSame('Rosa actualizada', $contact->name);
        $this->assertNull($contact->phone);
        $this->assertNull($contact->note);

        $this->actingAs($account)
            ->delete(route('students.contacts.destroy', [$student, $contact]))
            ->assertRedirect(route('students.show', $student))
            ->assertInertiaFlash('success', 'Contacto eliminado');

        $this->assertModelMissing($contact);
    }

    public function test_contact_writes_require_manage_permission_and_nested_ownership(): void
    {
        $student = Student::factory()->create();
        $otherStudent = Student::factory()->create();
        $otherContact = StudentContact::factory()->create([
            'student_code' => $otherStudent->code,
        ]);
        $viewer = $this->createEmployeeAccount();
        $this->grantPermissions($viewer, ['students.view']);

        $this->actingAs($viewer)
            ->post(route('students.contacts.store', $student), [
                'name' => 'Contacto',
            ])
            ->assertForbidden();

        $manager = $this->createEmployeeAccount();
        $this->grantPermissions($manager, ['students.manage']);

        $this->actingAs($manager)
            ->put(route('students.contacts.update', [$student, $otherContact]), [
                'name' => 'Contacto ajeno',
            ])
            ->assertNotFound();

        $this->assertNotSame('Contacto ajeno', $otherContact->fresh()->name);
    }

    public function test_directory_is_institution_wide_recent_paginated_and_does_not_load_contacts(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['students.view']);

        foreach (range(1, 12) as $index) {
            $student = Student::factory()->create([
                'first_name' => "Estudiante {$index}",
                'created_at' => now()->subDays(12 - $index),
                'updated_at' => now()->subDays(12 - $index),
            ]);

            if ($index === 12) {
                StudentContact::factory()->create(['student_code' => $student->code]);
            }
        }

        $response = $this->actingAs($account)
            ->get(route('students.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Students/Index')
                ->where('students.total', 12)
                ->where('students.per_page', 10)
                ->has('students.data', 10));

        $rows = $response->inertiaProps('students.data');
        $this->assertSame('Estudiante 12', $rows[0]['first_name']);
        $this->assertArrayNotHasKey('contacts', $rows[0]);

        $secondPage = $this->actingAs($account)
            ->get(route('students.index', ['page' => 2]))
            ->assertOk();

        $this->assertCount(2, $secondPage->inertiaProps('students.data'));
    }

    public function test_search_matches_combined_name_and_ranks_exact_dni_first(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['students.view']);
        $exact = Student::factory()->create([
            'dni' => '71234567',
            'first_name' => 'Zulema',
            'last_name' => 'Zuluaga',
        ]);
        Student::factory()->create([
            'dni' => '80000001',
            'first_name' => '71234567',
            'last_name' => 'Alarcón',
        ]);
        $fullName = Student::factory()->create([
            'dni' => '80000002',
            'first_name' => 'María Elena',
            'last_name' => 'Quispe Mamani',
        ]);

        $dniRows = $this->actingAs($account)
            ->get(route('students.index', ['q' => '71234567']))
            ->assertOk()
            ->inertiaProps('students.data');
        $this->assertSame($exact->code, $dniRows[0]['code']);

        $nameRows = $this->actingAs($account)
            ->get(route('students.index', ['q' => 'María Elena Quispe']))
            ->assertOk()
            ->inertiaProps('students.data');
        $this->assertSame([$fullName->code], array_column($nameRows, 'code'));
    }

    public function test_profile_loads_one_ordered_aggregate_and_missing_students_return_404(): void
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['students.view']);
        $student = Student::factory()->create();
        StudentContact::factory()->create([
            'student_code' => $student->code,
            'position' => 2,
            'name' => 'Segundo',
        ]);
        StudentContact::factory()->create([
            'student_code' => $student->code,
            'position' => 1,
            'name' => 'Primero',
        ]);

        $response = $this->actingAs($account)
            ->get(route('students.show', $student))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Students/Show')
                ->where('student.code', $student->code)
                ->has('student.contacts', 2));

        $this->assertSame(
            ['Primero', 'Segundo'],
            array_column($response->inertiaProps('student.contacts'), 'name'),
        );

        $this->actingAs($account)
            ->get(route('students.show', Str::uuid()))
            ->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'dni' => '71234567',
            'first_name' => 'María Elena',
            'last_name' => 'Quispe Mamani',
            'birth_date' => '2015-04-20',
            'phone' => '987 654 321',
            'address' => 'Av. La Cultura 123',
            'observation' => 'Información relevante',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{dni: string, first_name: string, last_name: string, birth_date: ?string, phone: ?string, address: ?string, observation: ?string}
     */
    private function studentAttributes(array $overrides = []): array
    {
        return array_merge([
            'dni' => '87654321',
            'first_name' => 'María Elena',
            'last_name' => 'Quispe Mamani',
            'birth_date' => null,
            'phone' => null,
            'address' => null,
            'observation' => null,
        ], $overrides);
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
