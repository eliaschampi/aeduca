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

        $this->assertSame(9, Permission::query()->count());
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
