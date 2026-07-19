<script lang="ts">
    /**
     * Role assignable permission scope.
     * Progressive disclosure: search + one domain at a time.
     * No bulk “select all” — full access is superadministrator, not a role checkbox wall.
     */
    import { untrack } from 'svelte';
    import {
        Button,
        Checkbox,
        EmptyState,
        Input,
        List,
        ListHeader,
        ListItem,
    } from '@lumi-ui/svelte';

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
        selectedCodes: string[];
        canEdit: boolean;
        error?: string | null;
    }

    let { permission_groups, selectedCodes = $bindable(), canEdit, error = null }: Props = $props();

    const groupLabels: Record<string, string> = {
        dashboard: 'Inicio',
        branches: 'Sedes',
        employees: 'Usuarios',
        roles: 'Roles',
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
    const permissionByName = $derived.by(() => {
        const map = new Map<string, PermissionItem>();
        for (const group of permission_groups) {
            for (const permission of group.permissions) {
                map.set(permission.name, permission);
            }
        }
        return map;
    });

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

    const domainNav = $derived(
        permission_groups.map((group) => ({
            key: group.group,
            title: groupTitle(group.group),
            selected: selectedInGroup(group),
            total: group.permissions.length,
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

    function isViewLockedByManage(name: string): boolean {
        if (!name.endsWith('.view')) return false;
        const manage = permissionByName.get(name.replace(/\.view$/, '.manage'));
        return Boolean(manage && selectedSet.has(manage.code));
    }

    function togglePermission(code: string, checked: boolean): void {
        const permission = permission_groups
            .flatMap((g) => g.permissions)
            .find((p) => p.code === code);

        let next = checked
            ? selectedSet.has(code)
                ? selectedCodes
                : [...selectedCodes, code]
            : selectedCodes.filter((current) => current !== code);

        if (permission?.name.endsWith('.manage') && checked) {
            const view = permissionByName.get(permission.name.replace(/\.manage$/, '.view'));
            if (view && !next.includes(view.code)) {
                next = [...next, view.code];
            }
        }

        if (permission?.name.endsWith('.view') && !checked) {
            const manage = permissionByName.get(permission.name.replace(/\.view$/, '.manage'));
            if (manage) {
                next = next.filter((c) => c !== manage.code);
            }
        }

        selectedCodes = next;
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
            <aside
                class="lumi-stack lumi-stack--xs lumi-min-width--0"
                aria-label="Áreas de permisos"
            >
                {#each domainNav as domain (domain.key)}
                    <Button
                        type="button"
                        variant={activeGroup === domain.key && !isSearching ? 'filled' : 'border'}
                        size="sm"
                        class="lumi-width--full lumi-justify--between"
                        onclick={() => {
                            activeGroup = domain.key;
                            query = '';
                        }}
                    >
                        <span>{domain.title}</span>
                        <span class="lumi-text--xs">{domain.selected}/{domain.total}</span>
                    </Button>
                {/each}
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
                                    disabled={!canEdit || isViewLockedByManage(permission.name)}
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
