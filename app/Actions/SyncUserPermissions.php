<?php

namespace App\Actions;

use App\Models\User;
use App\Support\Authorization\PermissionDependency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Replace direct permission grants for an employee.
 * Only codes inside the role permission scope are accepted.
 * manage implies view is expanded before persist.
 */
final class SyncUserPermissions
{
    /**
     * @param  list<string>  $permissionCodes
     */
    public function handle(User $employee, array $permissionCodes): User
    {
        return DB::transaction(function () use ($employee, $permissionCodes): User {
            $employee->loadMissing('employeeRole.permissionScopes');

            $scopeCodes = $employee->employeeRole->permissionScopes
                ->pluck('code')
                ->all();
            $scopeSet = array_fill_keys($scopeCodes, true);

            $expanded = PermissionDependency::expandCodes($permissionCodes);

            foreach ($expanded as $code) {
                if (! isset($scopeSet[$code])) {
                    throw ValidationException::withMessages([
                        'permission_codes' => 'Uno de los permisos no está disponible para el rol de este usuario.',
                    ]);
                }
            }

            $employee->permissions()->sync($expanded);

            return $employee->refresh();
        });
    }
}
