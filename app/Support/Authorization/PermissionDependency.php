<?php

namespace App\Support\Authorization;

use App\Models\Permission;
use Illuminate\Support\Collection;

/**
 * Domain invariant: *.manage requires the matching *.view.
 */
final class PermissionDependency
{
    /** @var list<string> */
    private const MANAGE_VIEW_PAIRS = [
        'branches.manage' => 'branches.view',
        'employees.manage' => 'employees.view',
        'roles.manage' => 'roles.view',
    ];

    /**
     * Expand permission names so every manage includes its view.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function expandNames(array $names): array
    {
        $set = array_fill_keys($names, true);

        foreach (self::MANAGE_VIEW_PAIRS as $manage => $view) {
            if (isset($set[$manage])) {
                $set[$view] = true;
            }
        }

        $expanded = array_keys($set);
        sort($expanded);

        return $expanded;
    }

    /**
     * Expand permission codes via catalog names.
     *
     * @param  list<string>  $permissionCodes
     * @return list<string>
     */
    public static function expandCodes(array $permissionCodes): array
    {
        if ($permissionCodes === []) {
            return [];
        }

        /** @var Collection<string, string> $nameByCode */
        $nameByCode = Permission::query()
            ->whereIn('code', $permissionCodes)
            ->pluck('name', 'code');

        $names = $nameByCode->values()->all();
        $expandedNames = self::expandNames($names);

        if (count($expandedNames) === count($names)) {
            return array_values(array_unique($permissionCodes));
        }

        $codeByName = Permission::query()
            ->whereIn('name', $expandedNames)
            ->pluck('code', 'name');

        return $codeByName->values()->unique()->values()->all();
    }
}
