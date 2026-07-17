<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateEmployee
{
    /**
     * @param  array{first_name: string, last_name: string, email: ?string, phone: ?string, employee_role_code: string, is_active: bool}  $profile
     * @param  list<string>  $branchCodes
     */
    public function handle(User $employee, array $profile, array $branchCodes): User
    {
        return DB::transaction(function () use ($employee, $profile, $branchCodes): User {
            $employee->update($profile);

            $employee->branches()->sync($branchCodes);

            return $employee;
        });
    }
}
