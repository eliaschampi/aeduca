<?php

namespace App\Actions;

use App\Models\AuthAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create employee profile, credentials, and branch membership.
 * Direct permissions start empty; assign via SyncUserPermissions on the profile.
 */
final class CreateEmployee
{
    /**
     * @param  array{first_name: string, last_name: string, email: ?string, phone: ?string, employee_role_code: string, is_active: bool}  $profile
     * @param  list<string>  $branchCodes
     */
    public function handle(array $profile, array $branchCodes, string $login, string $password): User
    {
        return DB::transaction(function () use ($profile, $branchCodes, $login, $password): User {
            $employee = User::query()->create($profile);

            $employee->branches()->attach($branchCodes);

            AuthAccount::query()->create([
                'login' => $login,
                'password' => $password,
                'user_code' => $employee->code,
                'is_active' => true,
            ]);

            return $employee;
        });
    }
}
