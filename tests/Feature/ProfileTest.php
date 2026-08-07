<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    public function test_employee_can_open_their_profile_without_admin_permissions(): void
    {
        Storage::fake('local');
        $account = $this->createEmployeeAccount();
        // Intentionally no employees.view / employees.manage.
        $photoPath = 'employee-photos/current.webp';
        $account->user->forceFill([
            'email' => 'ana@example.com',
            'phone' => '999111222',
            'photo_path' => $photoPath,
        ])->save();
        Storage::disk('local')->put($photoPath, 'photo');

        $this->actingAs($account)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Employees/Profile')
                ->where('is_self', true)
                ->where('active_tab', 'general')
                ->where('employee.code', $account->user->code)
                ->where('employee.first_name', $account->user->first_name)
                ->where('employee.last_name', $account->user->last_name)
                ->where('employee.email', 'ana@example.com')
                ->where('employee.phone', '999111222')
                ->where('employee.role_name', $account->user->employeeRole->name)
                ->where('employee.login', $account->login)
                ->where(
                    'employee.photo_url',
                    route('admin.employees.photo', [
                        'employee' => $account->user,
                        'v' => substr(hash('sha256', $photoPath), 0, 16),
                    ]),
                )
                ->missing('employee.photo_path')
                ->has('employee.branches', 1));
    }

    public function test_profile_photo_update_returns_to_profile_and_refreshes_versioned_url(): void
    {
        Storage::fake('local');
        $account = $this->createEmployeeAccount();
        $account->user->forceFill(['photo_path' => 'employee-photos/old.webp'])->save();
        Storage::disk('local')->put('employee-photos/old.webp', 'old');

        $before = $this->actingAs($account)
            ->get(route('profile.show'))
            ->inertiaProps('employee.photo_url');

        $this->actingAs($account)
            ->withHeader('Referer', route('profile.show'))
            ->put(route('admin.employees.photo.update', $account->user), [
                'photo' => UploadedFile::fake()->image('me.jpg', 512, 512),
            ])
            ->assertRedirect(route('profile.show'));

        $newPath = $account->user()->firstOrFail()->photo_path;
        $this->assertNotNull($newPath);
        $this->assertNotSame('employee-photos/old.webp', $newPath);
        Storage::disk('local')->assertMissing('employee-photos/old.webp');
        Storage::disk('local')->assertExists($newPath);

        $account->unsetRelation('user');
        $after = $this->actingAs($account)
            ->get(route('profile.show'))
            ->inertiaProps('employee.photo_url');
        $this->assertNotSame($before, $after);
        $this->assertMatchesRegularExpression('/[?&]v=[a-f0-9]{16}$/', $after);
        $this->assertStringNotContainsString($newPath, $after);
    }

    public function test_guest_cannot_open_profile(): void
    {
        $this->get(route('profile.show'))->assertRedirect(route('login'));
    }

    public function test_student_actor_cannot_open_employee_profile(): void
    {
        $account = $this->createStudentAccount();

        $this->actingAs($account)
            ->get(route('profile.show'))
            ->assertForbidden();
    }

    public function test_employee_still_cannot_update_another_employee_photo_from_profile_flow(): void
    {
        Storage::fake('local');
        $account = $this->createEmployeeAccount();
        $target = User::factory()->create([
            'employee_role_code' => $account->user->employee_role_code,
            'photo_path' => 'employee-photos/secret.webp',
        ]);
        Storage::disk('local')->put($target->photo_path, 'secret');

        $this->actingAs($account)
            ->withHeader('Referer', route('profile.show'))
            ->put(route('admin.employees.photo.update', $target), [
                'photo' => UploadedFile::fake()->image('x.jpg', 320, 320),
            ])
            ->assertForbidden();
    }
}
