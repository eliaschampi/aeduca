<?php

namespace App\Actions;

use App\Models\EmployeeRole;
use App\Models\User;
use App\Support\Authorization\PermissionDependency;
use Illuminate\Support\Facades\DB;

/**
 * Create/update a role and its assignable permission scope.
 * Reducing the scope prunes incompatible direct user grants.
 */
final class SaveRole
{
    /**
     * @param  array{name: string, description: ?string, is_active: bool}  $attributes
     * @param  list<string>  $permissionCodes
     */
    public function handle(?EmployeeRole $role, array $attributes, array $permissionCodes): EmployeeRole
    {
        return DB::transaction(function () use ($role, $attributes, $permissionCodes): EmployeeRole {
            $expandedCodes = PermissionDependency::expandCodes($permissionCodes);

            if ($role === null) {
                $role = EmployeeRole::query()->create($attributes);
            } else {
                $role->update($attributes);
            }

            $role->permissionScopes()->sync($expandedCodes);

            $this->pruneIncompatibleUserGrants($role, $expandedCodes);

            return $role->refresh();
        });
    }

    /**
     * @param  list<string>  $allowedPermissionCodes
     */
    private function pruneIncompatibleUserGrants(EmployeeRole $role, array $allowedPermissionCodes): void
    {
        $allowed = array_fill_keys($allowedPermissionCodes, true);

        User::query()
            ->where('employee_role_code', $role->code)
            ->where('is_super_admin', false)
            ->with('permissions:code')
            ->orderBy('code')
            ->each(function (User $user) use ($allowed): void {
                $keep = $user->permissions
                    ->pluck('code')
                    ->filter(fn (string $code): bool => isset($allowed[$code]))
                    ->values()
                    ->all();

                $user->permissions()->sync($keep);
            });
    }
}
