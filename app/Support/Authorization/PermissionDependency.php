<?php

namespace App\Support\Authorization;

use App\Models\Permission;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Domain invariant: write capabilities require the matching domain view.
 */
final class PermissionDependency
{
    /** @var list<string> */
    private const VIEW_DEPENDENT_SUFFIXES = ['.manage', '.delete'];

    /**
     * Expand permission names so every write capability includes its view.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function expandNames(array $names): array
    {
        $set = array_fill_keys($names, true);

        foreach ($names as $name) {
            foreach (self::VIEW_DEPENDENT_SUFFIXES as $suffix) {
                if (! str_ends_with($name, $suffix)) {
                    continue;
                }

                $set[substr($name, 0, -strlen($suffix)).'.view'] = true;

                break;
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

        $missingNames = array_values(array_diff($expandedNames, $codeByName->keys()->all()));

        if ($missingNames !== []) {
            throw new LogicException(
                'Missing permission dependencies: '.implode(', ', $missingNames),
            );
        }

        return collect($expandedNames)
            ->map(fn (string $name): string => $codeByName->get($name))
            ->unique()
            ->values()
            ->all();
    }
}
