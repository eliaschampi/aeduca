<?php

namespace App\Support\Authorization;

use App\Models\Permission;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Domain permission dependencies expanded before role scope or direct grants.
 */
final class PermissionDependency
{
    /**
     * @var array<string, list<string>>
     */
    private const array DEPENDENCIES = [
        'enrollments.view' => ['students.view'],
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

        do {
            $before = count($set);

            foreach (array_keys($set) as $name) {
                foreach (self::directDependencies($name) as $dependency) {
                    $set[$dependency] = true;
                }
            }
        } while (count($set) !== $before);

        $expanded = array_keys($set);
        sort($expanded);

        return $expanded;
    }

    /**
     * Direct dependency map for presentation clients. Persistence still calls
     * expandNames/expandCodes and remains the security owner.
     *
     * @param  list<string>  $names
     * @return array<string, list<string>>
     */
    public static function dependencyMap(array $names): array
    {
        $map = [];

        foreach ($names as $name) {
            $dependencies = self::directDependencies($name);

            if ($dependencies !== []) {
                $map[$name] = $dependencies;
            }
        }

        ksort($map);

        return $map;
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

    /**
     * @return list<string>
     */
    private static function directDependencies(string $name): array
    {
        $dependencies = self::DEPENDENCIES[$name] ?? [];

        if (str_ends_with($name, '.manage')) {
            $dependencies[] = substr($name, 0, -strlen('.manage')).'.view';
        }

        return array_values(array_unique($dependencies));
    }
}
