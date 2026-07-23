<script lang="ts">
    /**
     * Role assignable permission scope.
     * Progressive disclosure: search + one domain at a time.
     * No bulk “select all” — full access is superadministrator, not a role checkbox wall.
     */
    import { untrack } from 'svelte';
    import {
        Checkbox,
        EmptyState,
        Input,
        List,
        ListHeader,
        ListItem,
        SegmentedControl,
    } from '@lumi-ui/svelte';
    import {
        isPermissionRequired,
        togglePermissionCodes,
        type PermissionDependencyMap,
    } from '@/lib/permission-dependencies';

    interface PermissionItem {
        code: string;
        name: string;
        description: string | null;
    }

    interface PermissionGroup {
        group: string;
        permissions: PermissionItem[];
    }

    interface Props {
        permission_groups: PermissionGroup[];
        permission_dependencies: PermissionDependencyMap;
        selectedCodes: string[];
        canEdit: boolean;
        error?: string | null;
    }

    let {
        permission_groups,
        permission_dependencies,
        selectedCodes = $bindable(),
        canEdit,
        error = null,
    }: Props = $props();

    const groupLabels: Record<string, string> = {
        dashboard: 'Inicio',
        branches: 'Sedes',
        cycles: 'Ciclos',
        employees: 'Usuarios',
        enrollments: 'Matrículas',
        roles: 'Roles',
        students: 'Estudiantes',
    };

    let query = $state('');
    let activeGroup = $state<string>(untrack(() => permission_groups[0]?.group ?? ''));
    let showSelectedOnly = $state(false);

    const catalogCount = $derived(
        permission_groups.reduce((sum, g) => sum + g.permissions.length, 0),
    );
    const selectedCount = $derived(selectedCodes.length);
    /** O(1) membership for large catalogs. */
    const selectedSet = $derived(new Set(selectedCodes));
    const permissions = $derived(permission_groups.flatMap((group) => group.permissions));

    const normalizedQuery = $derived(query.trim().toLowerCase());
    const isSearching = $derived(normalizedQuery.length > 0);

    function groupTitle(group: string): string {
        return groupLabels[group] ?? group.charAt(0).toUpperCase() + group.slice(1);
    }

    function permissionLabel(permission: PermissionItem): string {
        return permission.description?.trim() || permission.name;
    }

    function selectedInGroup(group: PermissionGroup): number {
        return group.permissions.filter((p) => selectedSet.has(p.code)).length;
    }

    function matchesQuery(permission: PermissionItem): boolean {
        if (!isSearching) return true;
        const hay = `${permission.name} ${permission.description ?? ''}`.toLowerCase();
        return hay.includes(normalizedQuery);
    }

    const domainOptions = $derived(
        permission_groups.map((group) => ({
            value: group.group,
            label: `${groupTitle(group.group)} · ${selectedInGroup(group)}/${group.permissions.length}`,
        })),
    );

    const visibleRows = $derived.by(() => {
        const source = isSearching
            ? permission_groups.flatMap((g) =>
                  g.permissions
                      .filter(matchesQuery)
                      .map((p) => ({ permission: p, groupKey: g.group })),
              )
            : (permission_groups.find((g) => g.group === activeGroup)?.permissions ?? []).map(
                  (p) => ({ permission: p, groupKey: activeGroup }),
              );

        if (!showSelectedOnly) return source;
        return source.filter(({ permission }) => selectedSet.has(permission.code));
    });

    const listTitle = $derived(
        isSearching
            ? `Resultados · ${visibleRows.length}`
            : `${groupTitle(activeGroup)} · ${visibleRows.length}`,
    );

    function togglePermission(code: string, checked: boolean): void {
        selectedCodes = togglePermissionCodes(
            code,
            checked,
            selectedCodes,
            permissions,
            permission_dependencies,
        );
    }
</script>

<div class="lumi-stack lumi-stack--md">
    <p class="lumi-text--sm lumi-text--muted lumi-margin--none">
        {selectedCount} de {catalogCount} disponibles · selección explícita (acceso total = superadmin)
    </p>

    <Input
        bind:value={query}
        icon="search"
        placeholder="Buscar permiso por nombre o descripción…"
        aria-label="Buscar permisos"
    />

    <Checkbox
        label="Solo seleccionados"
        checked={showSelectedOnly}
        onchange={(checked) => {
            showSelectedOnly = checked;
        }}
    />

    {#if catalogCount === 0}
        <EmptyState
            icon="shield"
            title="Sin catálogo de permisos"
            description="Aún no hay permisos registrados en el sistema."
        />
    {:else}
        <div class="lumi-layout--two-columns lumi-layout--sidebar-left">
            <aside class="lumi-min-width--0">
                <SegmentedControl
                    value={isSearching ? '' : activeGroup}
                    options={domainOptions}
                    orientation="vertical"
                    fullWidth
                    aria-label="Áreas de permisos"
                    onchange={(value) => {
                        activeGroup = String(value);
                        query = '';
                    }}
                />
            </aside>

            <div class="lumi-min-width--0">
                {#if visibleRows.length === 0}
                    <EmptyState
                        icon="search"
                        title={showSelectedOnly ? 'Nada seleccionado aquí' : 'Sin coincidencias'}
                        description={isSearching
                            ? 'Prueba con otras palabras o limpia la búsqueda.'
                            : 'Cambia de área o desactiva el filtro de seleccionados.'}
                    />
                {:else}
                    <List size="sm" maxHeight="lg">
                        <ListHeader title={listTitle} icon="shield" />
                        {#each visibleRows as row (row.permission.code)}
                            {@const permission = row.permission}
                            <ListItem
                                title={permissionLabel(permission)}
                                subtitle={isSearching
                                    ? `${groupTitle(row.groupKey)} · ${permission.name}`
                                    : permission.name}
                            >
                                <Checkbox
                                    checked={selectedSet.has(permission.code)}
                                    disabled={!canEdit ||
                                        isPermissionRequired(
                                            permission.name,
                                            selectedCodes,
                                            permissions,
                                            permission_dependencies,
                                        )}
                                    aria-label={permissionLabel(permission)}
                                    onchange={(checked) =>
                                        togglePermission(permission.code, checked)}
                                />
                            </ListItem>
                        {/each}
                    </List>
                {/if}
            </div>
        </div>
    {/if}

    {#if error}
        <span class="lumi-text--sm lumi-text--danger">{error}</span>
    {/if}
</div>
