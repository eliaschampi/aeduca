<script lang="ts">
    import { untrack } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import {
        Button,
        Card,
        Checkbox,
        Chip,
        Collapse,
        Input,
        PageHeader,
        Switch,
        Tabs,
        Textarea,
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

    interface RolePayload {
        code: string;
        name: string;
        description: string | null;
        is_active: boolean;
        permission_codes: string[];
    }

    type RoleTab = 'identity' | 'permissions';

    interface Props {
        role?: RolePayload | null;
        permission_groups: PermissionGroup[];
        /** From backend Gate; create always true. */
        can_manage?: boolean;
    }

    const {
        role = null,
        permission_groups,
        can_manage = false,
    }: Props = $props();

    const isCreate = $derived(role === null);
    const canEdit = $derived(can_manage);

    const groupLabels: Record<string, string> = {
        dashboard: 'Inicio',
        branches: 'Sedes',
        employees: 'Usuarios',
        roles: 'Roles',
    };

    function seedForm(): {
        name: string;
        description: string;
        is_active: boolean;
        permission_codes: string[];
    } {
        return {
            name: role?.name ?? '',
            description: role?.description ?? '',
            is_active: role?.is_active ?? true,
            permission_codes: [...(role?.permission_codes ?? [])],
        };
    }

    let form = $state(untrack(() => seedForm()));
    // Seed tab once from create vs edit; do not rebind to reactive isCreate.
    let activeTab = $state<RoleTab>(untrack(() => (role === null ? 'identity' : 'permissions')));
    let processing = $state(false);
    let errors = $state<Record<string, string>>({});

    const selectedCount = $derived(form.permission_codes.length);
    const catalogCount = $derived(
        permission_groups.reduce((sum, group) => sum + group.permissions.length, 0),
    );

    const tabs = [
        { value: 'identity', label: 'Identidad', icon: 'tag' },
        { value: 'permissions', label: 'Permisos', icon: 'shield' },
    ];

    function groupTitle(group: string): string {
        return groupLabels[group] ?? group.charAt(0).toUpperCase() + group.slice(1);
    }

    function permissionLabel(permission: PermissionItem): string {
        return permission.description?.trim() || permission.name;
    }

    function selectedInGroup(group: PermissionGroup): number {
        return group.permissions.filter((p) => form.permission_codes.includes(p.code)).length;
    }

    function isChecked(code: string): boolean {
        return form.permission_codes.includes(code);
    }

    function togglePermission(code: string, checked: boolean): void {
        form.permission_codes = checked
            ? [...form.permission_codes, code]
            : form.permission_codes.filter((current) => current !== code);
    }

    function toggleGroup(group: PermissionGroup, checked: boolean): void {
        const codes = group.permissions.map((p) => p.code);
        if (checked) {
            form.permission_codes = [...new Set([...form.permission_codes, ...codes])];
            return;
        }
        form.permission_codes = form.permission_codes.filter((code) => !codes.includes(code));
    }

    function groupFullySelected(group: PermissionGroup): boolean {
        return (
            group.permissions.length > 0 &&
            group.permissions.every((p) => form.permission_codes.includes(p.code))
        );
    }

    function submit(): void {
        if (processing || !canEdit) return;

        const payload = { ...form };
        const options = {
            onStart: () => {
                processing = true;
                errors = {};
            },
            onError: (formErrors: Record<string, string>) => {
                errors = formErrors;
                if (errors.name || errors.description || errors.is_active) {
                    activeTab = 'identity';
                }
            },
            onFinish: () => {
                processing = false;
            },
        };

        if (isCreate) {
            router.post('/admin/roles', payload, options);
        } else if (role) {
            router.put(`/admin/roles/${role.code}`, payload, options);
        }
    }
</script>

<svelte:head>
    <title>{isCreate ? 'Nuevo rol' : form.name || 'Rol'} · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title={isCreate ? 'Nuevo rol' : form.name || 'Rol'}
        subtitle="Identidad del cargo y paquete de permisos por defecto."
        icon="shield"
        size="xl"
    >
        {#snippet actions()}
            <Button
                type="button"
                variant="border"
                icon="arrowLeft"
                onclick={() => router.visit('/admin/roles')}
            >
                Roles
            </Button>
        {/snippet}
    </PageHeader>

    <div class="lumi-flex lumi-flex--wrap lumi-flex--gap-sm lumi-align-items--center">
        <Chip color="info" size="sm" icon="shield">
            {selectedCount} de {catalogCount} permisos
        </Chip>
        <Chip color={form.is_active ? 'success' : 'secondary'} size="sm">
            {form.is_active ? 'Activo' : 'Inactivo'}
        </Chip>
    </div>

    <Tabs
        bind:value={activeTab}
        {tabs}
        color="primary"
        aria-label="Secciones del rol"
    />

    <form
        class="lumi-stack lumi-stack--lg"
        onsubmit={(event) => {
            event.preventDefault();
            submit();
        }}
    >
        {#if activeTab === 'identity'}
            <Card title="Identidad" subtitle="Nombre y estado del rol." spaced>
                <div class="lumi-stack lumi-stack--md">
                    <Input
                        label="Nombre"
                        placeholder="Ej. Secretaría"
                        bind:value={form.name}
                        maxlength={100}
                        required
                        disabled={!canEdit}
                        danger={!!errors.name}
                        dangerText={errors.name}
                    />
                    <Textarea
                        label="Descripción (opcional)"
                        placeholder="Para qué se usa este rol en la institución"
                        bind:value={form.description}
                        rows={2}
                        disabled={!canEdit}
                    />
                    <Switch
                        bind:checked={form.is_active}
                        label="Rol activo"
                        disabled={!canEdit}
                    />
                </div>
            </Card>
        {:else}
            <Card
                title="Permisos por defecto"
                subtitle="Lo que este cargo puede hacer. Los casos especiales van en cada usuario."
                spaced
            >
                <div class="lumi-stack lumi-stack--md">
                    {#if canEdit && catalogCount > 0}
                        <div class="lumi-flex lumi-flex--wrap lumi-flex--gap-sm">
                            <Button
                                type="button"
                                variant="border"
                                size="sm"
                                onclick={() => {
                                    form.permission_codes = permission_groups.flatMap((g) =>
                                        g.permissions.map((p) => p.code),
                                    );
                                }}
                            >
                                Marcar todos
                            </Button>
                            <Button
                                type="button"
                                variant="border"
                                size="sm"
                                onclick={() => {
                                    form.permission_codes = [];
                                }}
                            >
                                Limpiar
                            </Button>
                        </div>
                    {/if}

                    <div class="lumi-stack lumi-stack--sm">
                        {#each permission_groups as group (group.group)}
                            {@const selected = selectedInGroup(group)}
                            {@const total = group.permissions.length}
                            <Collapse
                                title={`${groupTitle(group.group)} · ${selected}/${total}`}
                                size="sm"
                            >
                                <div class="lumi-stack lumi-stack--sm">
                                    {#if canEdit}
                                        <Checkbox
                                            label="Todo el grupo"
                                            checked={groupFullySelected(group)}
                                            onchange={(checked) => toggleGroup(group, checked)}
                                        />
                                    {/if}
                                    <div class="lumi-grid lumi-grid--responsive lumi-grid--gap-sm">
                                        {#each group.permissions as permission (permission.code)}
                                            <Checkbox
                                                label={permissionLabel(permission)}
                                                checked={isChecked(permission.code)}
                                                disabled={!canEdit}
                                                onchange={(checked) =>
                                                    togglePermission(permission.code, checked)}
                                            />
                                        {/each}
                                    </div>
                                </div>
                            </Collapse>
                        {:else}
                            <p class="lumi-text--sm lumi-text--muted lumi-margin--none">
                                No hay permisos en el catálogo.
                            </p>
                        {/each}
                    </div>

                    {#if errors.permission_codes}
                        <span class="lumi-text--sm lumi-text--danger">{errors.permission_codes}</span>
                    {/if}
                </div>
            </Card>
        {/if}

        {#if canEdit}
            <div class="lumi-flex lumi-justify--end lumi-flex--gap-sm">
                <Button
                    type="button"
                    variant="border"
                    onclick={() => router.visit('/admin/roles')}
                >
                    Cancelar
                </Button>
                <Button type="submit" icon="check" loading={processing}>
                    {isCreate ? 'Crear rol' : 'Guardar rol'}
                </Button>
            </div>
        {/if}
    </form>
</div>
