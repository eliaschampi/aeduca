<?php

namespace App\Actions;

use App\Models\EmployeeRole;
use App\Models\EmployeeSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Update profile, role transition (prunes out-of-scope grants), and branch membership.
 * Employee administration is the sole write owner of user_branches.
 */
final class UpdateEmployee
{
    /**
     * @param  array{first_name: string, last_name: string, email: ?string, phone: ?string, dni: ?string, employee_role_code: string, is_active: bool}  $profile
     * @param  list<string>  $branchCodes
     */
    public function handle(User $employee, array $profile, array $branchCodes): User
    {
        return DB::transaction(function () use ($employee, $profile, $branchCodes): User {
            $locked = User::query()->whereKey($employee->code)->lockForUpdate()->firstOrFail();
            $currentBranchCodes = DB::table('user_branches')
                ->where('user_code', $locked->code)
                ->lockForUpdate()
                ->pluck('branch_code')
                ->all();
            $removedBranchCodes = array_values(array_diff($currentBranchCodes, $branchCodes));
            $today = CarbonImmutable::now(
                (string) config('aeduca.business_timezone', 'America/Lima'),
            )->toDateString();

            if ($removedBranchCodes !== []) {
                $hasDependentSchedule = EmployeeSchedule::query()
                    ->where('user_code', $locked->code)
                    ->whereIn('branch_code', $removedBranchCodes)
                    ->where(function ($validity) use ($today): void {
                        $validity->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today);
                    })
                    ->exists();

                if ($hasDependentSchedule) {
                    throw ValidationException::withMessages([
                        'branch_codes' => 'Primero retira los horarios vigentes de las sedes que deseas quitar.',
                    ]);
                }
            }

            if ($locked->is_active && ! $profile['is_active']) {
                $hasOpenSchedule = EmployeeSchedule::query()
                    ->where('user_code', $locked->code)
                    ->where(function ($validity) use ($today): void {
                        $validity->whereNull('ends_on')->orWhereDate('ends_on', '>', $today);
                    })
                    ->exists();

                if ($hasOpenSchedule) {
                    throw ValidationException::withMessages([
                        'is_active' => 'Primero retira los horarios abiertos antes de desactivar al usuario.',
                    ]);
                }
            }

            $previousRoleCode = $locked->employee_role_code;
            $locked->update($profile);

            if ($previousRoleCode !== $locked->employee_role_code) {
                $this->prunePermissionsToRoleScope($locked);
            }

            $locked->branches()->sync($branchCodes);

            if (
                $locked->preferred_branch_code
                && ! in_array($locked->preferred_branch_code, $branchCodes, true)
            ) {
                $locked->update(['preferred_branch_code' => null]);
            }

            return $locked->refresh();
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
