<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { Button, Chip, EmptyState, PageHeader, Table } from '@lumi-ui/svelte';
    import { can } from '@/lib/permissions';

    interface RoleRow {
        code: string;
        name: string;
        description: string | null;
        is_active: boolean;
        permissions_count: number;
        users_count: number;
    }

    interface Props {
        roles: RoleRow[];
    }

    const { roles }: Props = $props();
    const canManage = $derived(can('roles.manage'));

    function openRole(role: RoleRow): void {
        router.visit(`/admin/roles/${role.code}`);
    }
</script>

<svelte:head>
    <title>Roles · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title="Roles"
        subtitle="Cada rol categoriza al personal y define qué permisos se le pueden asignar. No otorga acceso por sí solo."
        icon="shield"
        size="xl"
    >
        {#snippet actions()}
            {#if canManage}
                <Button
                    type="button"
                    icon="plus"
                    onclick={() => router.visit('/admin/roles/create')}
                >
                    Nuevo rol
                </Button>
            {/if}
        {/snippet}
    </PageHeader>

    {#if roles.length === 0}
        <EmptyState
            icon="shield"
            title="Sin roles"
            description="Crea un rol y define qué permisos podrán asignarse a sus usuarios."
        >
            {#snippet actions()}
                {#if canManage}
                    <Button
                        type="button"
                        icon="plus"
                        onclick={() => router.visit('/admin/roles/create')}
                    >
                        Crear rol
                    </Button>
                {/if}
            {/snippet}
        </EmptyState>
    {:else}
        <Table
            data={roles}
            rowKey={(role) => role.code}
            hover
            onrow-click={openRole}
            noDataText="Aún no hay roles."
            aria-label="Roles del personal"
        >
            {#snippet thead()}
                <th scope="col">Nombre</th>
                <th scope="col">Permisos</th>
                <th scope="col">Usuarios</th>
                <th scope="col">Estado</th>
            {/snippet}

            {#snippet row({ row }: { row: RoleRow })}
                <td>
                    <!-- lumi-stack--2xs is the tightest public stack (there is no --3xs). -->
                    <div class="lumi-stack lumi-stack--2xs">
                        <span class="lumi-font--medium">{row.name}</span>
                        {#if row.description}
                            <span class="lumi-text--muted lumi-text--xs">{row.description}</span>
                        {/if}
                    </div>
                </td>
                <td>{row.permissions_count}</td>
                <td>{row.users_count}</td>
                <td>
                    <Chip color={row.is_active ? 'success' : 'secondary'} size="sm">
                        {row.is_active ? 'Activo' : 'Inactivo'}
                    </Chip>
                </td>
            {/snippet}
        </Table>
    {/if}
</div>
