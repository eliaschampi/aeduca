<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SyncUserPermissionOverrides
{
    /**
     * Replace all individual permission overrides for a user.
     *
     * Empty list means “inherit everything from the role”.
     *
     * @param  list<array{permission_code: string, is_allowed: bool}>  $overrides
     */
    public function handle(User $employee, array $overrides): User
    {
        return DB::transaction(function () use ($employee, $overrides): User {
            $sync = [];

            foreach ($overrides as $row) {
                $sync[$row['permission_code']] = [
                    'is_allowed' => $row['is_allowed'],
                ];
            }

            $employee->permissionOverrides()->sync($sync);

            return $employee->refresh();
        });
    }
}
