<?php

namespace Tests\Feature;

use App\Models\AuthAccount;
use App\Models\Branch;
use App\Models\EmployeeRole;
use App\Models\Permission;
use Database\Seeders\PermissionSeeder;
use RuntimeException;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    public function test_default_seed_only_syncs_the_permission_catalog(): void
    {
        config()->set('aeduca.seed_admin.login');
        config()->set('aeduca.seed_admin.password');

        $this->seed();

        $this->assertSame(22, Permission::query()->count());
        $this->assertSame(
            [
                'Alumnos',
                'Asistencia',
                'Ciclos',
                'Drive',
                'Inicio',
                'Matrículas',
                'Roles',
                'Sedes',
                'Usuarios',
            ],
            Permission::query()
                ->select('group_label')
                ->distinct()
                ->orderBy('group_label')
                ->pluck('group_label')
                ->all(),
        );
        $this->assertSame(
            'Alumnos',
            Permission::query()->where('name', 'students.view')->value('group_label'),
        );
        $this->assertEqualsCanonicalizing(
            ['employee_attendance.manage', 'employee_attendance.view'],
            Permission::query()
                ->where('name', 'like', 'employee_attendance.%')
                ->pluck('name')
                ->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['student_attentions.manage', 'student_attentions.view'],
            Permission::query()
                ->where('name', 'like', 'student_attentions.%')
                ->pluck('name')
                ->all(),
        );
        $this->assertSame(
            [
                'enrollments.delete',
                'enrollments.manage',
                'enrollments.view',
                'students.delete',
                'students.manage',
                'students.view',
            ],
            Permission::query()
                ->whereIn('name', [
                    'students.view',
                    'students.manage',
                    'students.delete',
                    'enrollments.view',
                    'enrollments.manage',
                    'enrollments.delete',
                ])
                ->orderBy('name')
                ->pluck('name')
                ->all(),
        );
        $this->assertSame(0, AuthAccount::query()->count());
    }

    public function test_permission_catalog_is_idempotent(): void
    {
        $this->seed(PermissionSeeder::class);
        $codes = Permission::query()->orderBy('name')->pluck('code', 'name')->all();

        $this->seed(PermissionSeeder::class);

        $this->assertSame(
            $codes,
            Permission::query()->orderBy('name')->pluck('code', 'name')->all(),
        );
    }

    public function test_administrator_bootstrap_requires_both_credentials(): void
    {
        config()->set('aeduca.seed_admin.login', 'bootstrap-admin');
        config()->set('aeduca.seed_admin.password');

        $this->expectException(RuntimeException::class);

        $this->seed();
    }

    public function test_optional_administrator_bootstrap_is_idempotent(): void
    {
        config()->set('aeduca.seed_admin.login', 'bootstrap-admin');
        config()->set('aeduca.seed_admin.password', 'bootstrap-secret');

        $this->seed();
        $this->seed();

        $this->assertSame(1, AuthAccount::query()->where('login', 'bootstrap-admin')->count());
        $this->assertSame(1, Branch::query()->where('name', 'Sede principal')->count());
        $this->assertSame(1, EmployeeRole::query()->where('name', 'Administración')->count());
        $this->assertSame(
            Permission::query()->count(),
            EmployeeRole::query()
                ->where('name', 'Administración')
                ->firstOrFail()
                ->permissionScopes()
                ->count(),
        );
    }
}
