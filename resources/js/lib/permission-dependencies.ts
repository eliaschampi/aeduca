export interface PermissionReference {
    code: string;
    name: string;
}

export type PermissionDependencyMap = Record<string, string[]>;

function requiredNames(name: string, dependencies: PermissionDependencyMap): Set<string> {
    const required = new Set<string>();
    const pending = [...(dependencies[name] ?? [])];

    while (pending.length > 0) {
        const dependency = pending.pop();
        if (!dependency || required.has(dependency)) continue;

        required.add(dependency);
        pending.push(...(dependencies[dependency] ?? []));
    }

    return required;
}

export function isPermissionRequired(
    name: string,
    selectedCodes: string[],
    permissions: PermissionReference[],
    dependencies: PermissionDependencyMap,
): boolean {
    const selectedNames = permissions
        .filter((permission) => selectedCodes.includes(permission.code))
        .map((permission) => permission.name);

    return selectedNames.some(
        (selectedName) =>
            selectedName !== name && requiredNames(selectedName, dependencies).has(name),
    );
}

export function togglePermissionCodes(
    code: string,
    checked: boolean,
    selectedCodes: string[],
    permissions: PermissionReference[],
    dependencies: PermissionDependencyMap,
): string[] {
    const permissionByCode = new Map(
        permissions.map((permission) => [permission.code, permission]),
    );
    const codeByName = new Map(permissions.map((permission) => [permission.name, permission.code]));
    const target = permissionByCode.get(code);

    if (!target) return selectedCodes;

    if (checked) {
        const requiredCodes = [...requiredNames(target.name, dependencies)]
            .map((name) => codeByName.get(name))
            .filter((dependencyCode): dependencyCode is string => Boolean(dependencyCode));

        return [...new Set([...selectedCodes, code, ...requiredCodes])];
    }

    return selectedCodes.filter((selectedCode) => {
        const selected = permissionByCode.get(selectedCode);

        return (
            selectedCode !== code &&
            (!selected || !requiredNames(selected.name, dependencies).has(target.name))
        );
    });
}
