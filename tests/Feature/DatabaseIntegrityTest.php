<?php

namespace Tests\Feature;

use App\Models\AuthAccount;
use App\Models\Permission;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DatabaseIntegrityTest extends TestCase
{
    public function test_login_must_be_unique(): void
    {
        $account = $this->createEmployeeAccount();

        $this->expectException(QueryException::class);

        AuthAccount::factory()->create(['login' => $account->login]);
    }

    public function test_login_must_be_normalized_at_the_database_boundary(): void
    {
        $this->expectException(QueryException::class);

        AuthAccount::factory()->create(['login' => 'Not-Normalized']);
    }

    public function test_permission_name_must_use_lowercase_dot_notation(): void
    {
        $this->expectException(QueryException::class);

        Permission::factory()->create(['name' => 'Users Create']);
    }

    public function test_branch_membership_rejects_unknown_foreign_keys(): void
    {
        $this->expectException(QueryException::class);

        DB::table('user_branches')->insert([
            'user_code' => Str::uuid(),
            'branch_code' => Str::uuid(),
        ]);
    }

    public function test_employee_role_cannot_be_deleted_while_in_use(): void
    {
        $account = $this->createEmployeeAccount();

        $this->expectException(QueryException::class);

        $account->user->employeeRole->delete();
    }

    public function test_deleting_an_employee_cascades_owned_access_records(): void
    {
        $account = $this->createEmployeeAccount();
        $permission = Permission::factory()->create();
        $account->user->permissionOverrides()->attach($permission, [
            'is_allowed' => true,
        ]);
        $employeeCode = $account->user->code;

        $account->user->delete();

        $this->assertDatabaseMissing('auth_accounts', ['code' => $account->code]);
        $this->assertDatabaseMissing('user_branches', ['user_code' => $employeeCode]);
        $this->assertDatabaseMissing('user_permissions', ['user_code' => $employeeCode]);
    }
}
