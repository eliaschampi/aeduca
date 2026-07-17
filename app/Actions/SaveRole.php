<?php

namespace App\Actions;

use App\Models\EmployeeRole;
use Illuminate\Support\Facades\DB;

final class SaveRole
{
    /**
     * Create or update a role and sync its default permission grants.
     *
     * @param  array{name: string, description: ?string, is_active: bool}  $attributes
     * @param  list<string>  $permissionCodes
     */
    public function handle(?EmployeeRole $role, array $attributes, array $permissionCodes): EmployeeRole
    {
        return DB::transaction(function () use ($role, $attributes, $permissionCodes): EmployeeRole {
            if ($role === null) {
                $role = EmployeeRole::query()->create($attributes);
            } else {
                $role->update($attributes);
            }

            $role->permissions()->sync($permissionCodes);

            return $role->refresh();
        });
    }
}
