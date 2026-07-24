<?php

namespace Tests\Feature;

use App\Models\AuthAccount;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StudentAuthenticationTest extends TestCase
{
    public function test_auth_account_requires_exactly_one_owner(): void
    {
        $employee = User::factory()->create();
        $student = Student::factory()->create();

        $this->expectException(QueryException::class);

        AuthAccount::factory()->create([
            'user_code' => $employee->code,
            'student_code' => $student->code,
        ]);
    }

    public function test_auth_account_rejects_an_ownerless_row(): void
    {
        $this->expectException(QueryException::class);

        AuthAccount::factory()->create([
            'user_code' => null,
            'student_code' => null,
        ]);
    }

    public function test_manager_enables_access_and_receives_one_time_temporary_credential(): void
    {
        $manager = $this->createEmployeeAccount();
        $this->grantPermissions($manager, ['students.manage']);
        $student = Student::factory()->create(['dni' => '76543210']);

        $response = $this->actingAs($manager)
            ->postJson(route('students.access.update', $student), [
                'operation' => 'enable',
            ])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $temporaryPassword = $response->json('credential.temporary_password');
        $account = $student->authAccount()->sole();

        $this->assertSame($student->dni, $response->json('credential.login'));
        $this->assertTrue(Hash::check($temporaryPassword, $account->password));
        $this->assertNotSame($temporaryPassword, $account->password);
        $this->assertFalse(
            DB::table('sessions')
                ->where('payload', 'like', '%'.$temporaryPassword.'%')
                ->exists(),
        );

        $this->actingAs($manager)
            ->get(route('students.show', $student))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('temporary_password'));
    }

    public function test_reset_replaces_hash_and_disable_does_not_change_student_state(): void
    {
        $manager = $this->createEmployeeAccount();
        $this->grantPermissions($manager, ['students.manage']);
        $studentAccount = $this->createStudentAccount();
        $student = $studentAccount->student;
        $oldHash = $studentAccount->password;

        $response = $this->actingAs($manager)
            ->postJson(route('students.access.update', $student), [
                'operation' => 'reset',
            ])
            ->assertOk();

        $this->assertNotSame($oldHash, $studentAccount->fresh()->password);
        $this->assertTrue(Hash::check(
            $response->json('credential.temporary_password'),
            $studentAccount->fresh()->password,
        ));

        $this->actingAs($manager)
            ->postJson(route('students.access.update', $student), [
                'operation' => 'disable',
            ])
            ->assertOk()
            ->assertJsonPath('credential', null);

        $this->assertFalse((bool) $studentAccount->fresh()->is_active);
        $this->assertTrue((bool) $student->fresh()->is_active);
    }

    public function test_student_can_login_without_enrollment_and_is_redirected_to_own_profile(): void
    {
        $account = $this->createStudentAccount([
            'password' => $this->validPassword,
        ]);

        $this->post(route('login.store'), [
            'login' => $account->login,
            'password' => $this->validPassword,
        ])
            ->assertRedirect(route('students.show', $account->student));

        $this->get(route('students.show', $account->student))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Students/Show')
                ->where('is_self', true)
                ->where('can_manage', false)
                ->where('contacts', [])
                ->where('auth.actor', 'student')
                ->where('auth.branches', [])
                ->where('auth.permissions', []));
    }

    public function test_student_cannot_open_another_profile_or_employee_routes(): void
    {
        $account = $this->createStudentAccount();
        $other = Student::factory()->create();

        $this->actingAs($account)
            ->get(route('students.show', $other))
            ->assertForbidden();

        $this->actingAs($account)
            ->get(route('branches.index'))
            ->assertForbidden();
    }

    public function test_inactive_student_receives_the_same_non_enumerating_login_error(): void
    {
        $account = $this->createStudentAccount(
            ['password' => $this->validPassword],
            ['is_active' => false],
        );

        $this->post(route('login.store'), [
            'login' => $account->login,
            'password' => $this->validPassword,
        ])
            ->assertSessionHasErrors([
                'login' => 'No pudimos iniciar sesión con esos datos.',
            ]);
        $this->assertGuest();
    }
}
