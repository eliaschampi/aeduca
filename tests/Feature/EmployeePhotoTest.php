<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeePhotoTest extends TestCase
{
    public function test_manager_uploads_and_reads_private_employee_photo(): void
    {
        Storage::fake('local');
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['employees.manage', 'employees.view']);
        $target = User::factory()->create([
            'employee_role_code' => $account->user->employee_role_code,
        ]);
        $target->branches()->attach($account->user->branches->first());

        $this->actingAs($account)
            ->withHeader('Referer', route('admin.employees.show', $target))
            ->put(route('admin.employees.photo.update', $target), [
                'photo' => UploadedFile::fake()->image('user.jpg', 640, 640),
            ])
            ->assertRedirect(route('admin.employees.show', $target));

        $photoPath = $target->fresh()->photo_path;
        $this->assertNotNull($photoPath);
        $this->assertStringStartsWith('employee-photos/', $photoPath);
        Storage::disk('local')->assertExists($photoPath);

        $this->actingAs($account)
            ->get(route('admin.employees.photo', $target))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, private');
    }

    public function test_replacing_employee_photo_removes_previous_file(): void
    {
        Storage::fake('local');
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['employees.manage']);
        $target = User::factory()->create([
            'employee_role_code' => $account->user->employee_role_code,
            'photo_path' => 'employee-photos/old.webp',
        ]);
        Storage::disk('local')->put($target->photo_path, 'old');
        $before = $this->actingAs($account)
            ->get(route('admin.employees.show', $target))
            ->inertiaProps('employee.photo_url');

        $this->actingAs($account)
            ->withHeader('Referer', route('admin.employees.show', $target))
            ->put(route('admin.employees.photo.update', $target), [
                'photo' => UploadedFile::fake()->image('new.png', 400, 400),
            ])
            ->assertRedirect(route('admin.employees.show', $target));

        $newPath = $target->fresh()->photo_path;
        $this->assertNotSame('employee-photos/old.webp', $newPath);
        Storage::disk('local')->assertMissing('employee-photos/old.webp');
        Storage::disk('local')->assertExists($newPath);
        $after = $this->actingAs($account)
            ->get(route('admin.employees.show', $target))
            ->inertiaProps('employee.photo_url');
        $this->assertNotSame($before, $after);
        $this->assertMatchesRegularExpression('/[?&]v=[a-f0-9]{16}$/', $after);
        $this->assertStringNotContainsString($newPath, $after);
    }

    public function test_non_square_employee_photo_is_rejected(): void
    {
        Storage::fake('local');
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['employees.manage']);
        $target = User::factory()->create([
            'employee_role_code' => $account->user->employee_role_code,
        ]);

        $this->actingAs($account)
            ->put(route('admin.employees.photo.update', $target), [
                'photo' => UploadedFile::fake()->image('wide.jpg', 640, 480),
            ])
            ->assertSessionHasErrors('photo');

        $this->assertNull($target->fresh()->photo_path);
    }

    public function test_stranger_without_manage_cannot_write_or_read_other_photo(): void
    {
        Storage::fake('local');
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['employees.view']);
        $target = User::factory()->create([
            'employee_role_code' => $account->user->employee_role_code,
            'photo_path' => 'employee-photos/secret.webp',
        ]);
        Storage::disk('local')->put($target->photo_path, 'secret');

        $this->actingAs($account)
            ->put(route('admin.employees.photo.update', $target), [
                'photo' => UploadedFile::fake()->image('x.jpg', 320, 320),
            ])
            ->assertForbidden();

        $viewer = $this->createEmployeeAccount();
        // No employees.view, not self.
        $this->actingAs($viewer)
            ->get(route('admin.employees.photo', $target))
            ->assertForbidden();
    }

    public function test_employee_can_read_and_write_own_photo_without_manage(): void
    {
        Storage::fake('local');
        $account = $this->createEmployeeAccount();
        // Intentionally no employees.manage / employees.view.

        $this->actingAs($account)
            ->withHeader('Referer', route('profile.show'))
            ->put(route('admin.employees.photo.update', $account->user), [
                'photo' => UploadedFile::fake()->image('me.jpg', 512, 512),
            ])
            ->assertRedirect(route('profile.show'));

        $photoPath = $account->user->fresh()->photo_path;
        $this->assertNotNull($photoPath);
        Storage::disk('local')->assertExists($photoPath);

        $this->actingAs($account)
            ->get(route('admin.employees.photo', $account->user))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_student_photo_suite_still_uses_shared_storage_helper(): void
    {
        Storage::fake('local');
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['students.manage', 'students.view']);
        $student = Student::factory()->create([
            'photo_path' => 'student-photos/old.webp',
        ]);
        Storage::disk('local')->put($student->photo_path, 'old');
        $before = $this->actingAs($account)
            ->get(route('students.show', $student))
            ->inertiaProps('student.photo_url');

        $this->actingAs($account)
            ->put(route('students.photo.update', $student), [
                'photo' => UploadedFile::fake()->image('ana.jpg', 640, 640),
            ])
            ->assertRedirect(route('students.show', $student));

        $path = $student->fresh()->photo_path;
        $this->assertNotNull($path);
        $this->assertStringStartsWith('student-photos/', $path);
        Storage::disk('local')->assertMissing('student-photos/old.webp');
        Storage::disk('local')->assertExists($path);
        $after = $this->actingAs($account)
            ->get(route('students.show', $student))
            ->inertiaProps('student.photo_url');
        $this->assertNotSame($before, $after);
        $this->assertMatchesRegularExpression('/[?&]v=[a-f0-9]{16}$/', $after);
        $this->assertStringNotContainsString($path, $after);
    }
}
