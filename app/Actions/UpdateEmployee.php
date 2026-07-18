<?php

namespace App\Actions;

use App\Models\EmployeeRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Update profile, role transition (prunes out-of-scope grants), and branch membership.
 * Employee administration is the sole write owner of user_branches.
 */
final class UpdateEmployee
{
    /**
     * @param  array{first_name: string, last_name: string, email: ?string, phone: ?string, employee_role_code: string, is_active: bool}  $profile
     * @param  list<string>  $branchCodes
     */
    public function handle(User $employee, array $profile, array $branchCodes): User
    {
        return DB::transaction(function () use ($employee, $profile, $branchCodes): User {
            $previousRoleCode = $employee->employee_role_code;
            $employee->update($profile);

            if ($previousRoleCode !== $employee->employee_role_code) {
                $this->prunePermissionsToRoleScope($employee);
            }

            $employee->branches()->sync($branchCodes);

            return $employee->refresh();
        });
    }

    private function prunePermissionsToRoleScope(User $employee): void
    {
        if ($employee->is_super_admin) {
            return;
        }

        $role = EmployeeRole::query()
            ->with('permissionScopes:code')
            ->find($employee->employee_role_code);

        if (! $role) {
            $employee->permissions()->sync([]);

            return;
        }

        $allowed = array_fill_keys(
            $role->permissionScopes->pluck('code')->all(),
            true,
        );

        $keep = $employee->permissions()
            ->pluck('permissions.code')
            ->filter(fn (string $code): bool => isset($allowed[$code]))
            ->values()
            ->all();

        $employee->permissions()->sync($keep);
    }
}
