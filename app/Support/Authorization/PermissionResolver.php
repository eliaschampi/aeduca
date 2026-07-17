<?php

namespace App\Support\Authorization;

use App\Models\AuthAccount;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\Request;

final class PermissionResolver
{
    private const string REQUEST_CACHE_KEY = self::class.'.effective_names';

    public function __construct(private readonly Request $request) {}

    public function can(AuthAccount $account, string $permissionName): bool
    {
        return in_array($permissionName, $this->effectiveNames($account), true);
    }

    /**
     * @return list<string>
     */
    public function effectiveNames(AuthAccount $account): array
    {
        if ($this->request->attributes->has(self::REQUEST_CACHE_KEY)) {
            /** @var list<string> */
            return $this->request->attributes->get(self::REQUEST_CACHE_KEY);
        }

        $names = $this->resolveEffectiveNames($account);
        $this->request->attributes->set(self::REQUEST_CACHE_KEY, $names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private function resolveEffectiveNames(AuthAccount $account): array
    {
        $employee = $this->activeEmployee($account);

        if (! $employee) {
            return [];
        }

        if ($employee->is_super_admin) {
            return Permission::query()
                ->orderBy('name')
                ->pluck('name')
                ->all();
        }

        $permissionNames = array_fill_keys(
            $employee->employeeRole->permissions()
                ->pluck('permissions.name')
                ->all(),
            true,
        );

        $overrides = $employee->permissionOverrides()
            ->orderBy('permissions.name')
            ->get(['permissions.name']);

        foreach ($overrides as $permission) {
            if ((bool) $permission->pivot->is_allowed) {
                $permissionNames[$permission->name] = true;
            } else {
                unset($permissionNames[$permission->name]);
            }
        }

        $names = array_keys($permissionNames);
        sort($names);

        return $names;
    }

    private function activeEmployee(AuthAccount $account): ?User
    {
        $account->loadMissing('user.employeeRole');
        $employee = $account->user;

        if (
            ! $account->is_active
            || ! $employee?->is_active
            || ! $employee->employeeRole?->is_active
        ) {
            return null;
        }

        return $employee;
    }
}
