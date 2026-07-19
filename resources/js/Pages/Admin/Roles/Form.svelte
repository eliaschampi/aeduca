<script lang="ts">
    import { untrack } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import { Button, Card, Chip, Input, PageHeader, Switch, Tabs, Textarea } from '@lumi-ui/svelte';
    import RolePermissionScope from './RolePermissionScope.svelte';

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
        can_manage?: boolean;
    }

    const { role = null, permission_groups, can_manage = false }: Props = $props();

    const isCreate = $derived(role === null);
    const canEdit = $derived(can_manage);

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
                } else if (errors.permission_codes) {
                    activeTab = 'permissions';
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
        subtitle="Identidad del cargo y permisos disponibles para este rol (no se otorgan solos)."
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

    <Tabs bind:value={activeTab} {tabs} color="primary" aria-label="Secciones del rol" />

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
                    <Switch bind:checked={form.is_active} label="Rol activo" disabled={!canEdit} />
                </div>
            </Card>
        {:else}
            <Card
                title="Permisos disponibles para este rol"
                subtitle="Elige por área o busca. No es un listado infinito de casillas."
                spaced
            >
                <RolePermissionScope
                    {permission_groups}
                    bind:selectedCodes={form.permission_codes}
                    {canEdit}
                    error={errors.permission_codes ?? null}
                />
            </Card>
        {/if}

        {#if canEdit}
            <div class="lumi-flex lumi-justify--end lumi-flex--gap-sm">
                <Button type="button" variant="border" onclick={() => router.visit('/admin/roles')}>
                    Cancelar
                </Button>
                <Button type="submit" icon="check" loading={processing}>
                    {isCreate ? 'Crear rol' : 'Guardar rol'}
                </Button>
            </div>
        {/if}
    </form>
</div>
