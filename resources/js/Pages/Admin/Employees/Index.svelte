<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { Button, Chip, EmptyState, PageHeader, Table, UserInfo } from '@lumi-ui/svelte';
    import { can } from '@/lib/permissions';

    interface EmployeeRow {
        code: string;
        first_name: string;
        last_name: string;
        email: string | null;
        role_name: string | null;
        login: string | null;
        is_active: boolean;
        access_active: boolean;
    }

    interface Props {
        employees: EmployeeRow[];
    }

    const { employees }: Props = $props();

    const canManage = $derived(can('employees.manage'));

    function openProfile(employee: EmployeeRow): void {
        router.visit(`/admin/employees/${employee.code}`);
    }
</script>

<svelte:head>
    <title>Usuarios · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title="Usuarios"
        subtitle="Personal autorizado, roles y credenciales de acceso."
        icon="users"
        size="xl"
    >
        {#snippet actions()}
            {#if canManage}
                <Button
                    type="button"
                    icon="userPlus"
                    onclick={() => router.visit('/admin/employees/create')}
                >
                    Nuevo usuario
                </Button>
            {/if}
        {/snippet}
    </PageHeader>

    {#if employees.length === 0}
        <EmptyState
            icon="users"
            title="Sin usuarios"
            description="Registra el primer usuario con su rol, sedes y acceso."
        >
            {#snippet actions()}
                {#if canManage}
                    <Button
                        type="button"
                        icon="userPlus"
                        onclick={() => router.visit('/admin/employees/create')}
                    >
                        Crear usuario
                    </Button>
                {/if}
            {/snippet}
        </EmptyState>
    {:else}
        <Table
            data={employees}
            rowKey={(employee) => employee.code}
            hover
            onrow-click={openProfile}
            noDataText="Aún no hay usuarios registrados."
            aria-label="Usuarios registrados"
        >
            {#snippet thead()}
                <th scope="col">Nombre</th>
                <th scope="col">Rol</th>
                <th scope="col">Usuario</th>
                <th scope="col">Estado</th>
            {/snippet}

            {#snippet row({ row }: { row: EmployeeRow })}
                <td>
                    <UserInfo
                        name={row.first_name}
                        lastName={row.last_name}
                        description={row.email ?? undefined}
                        avatarSize="sm"
                        avatarColor="primary"
                    />
                </td>
                <td>{row.role_name ?? '—'}</td>
                <td>
                    <span class="lumi-text--sm lumi-text--muted">{row.login ?? '—'}</span>
                </td>
                <td>
                    <div class="lumi-flex lumi-flex--gap-xs lumi-flex--wrap">
                        <Chip color={row.is_active ? 'success' : 'secondary'} size="sm">
                            {row.is_active ? 'Activo' : 'Inactivo'}
                        </Chip>
                        <Chip color={row.access_active ? 'info' : 'secondary'} size="sm">
                            {row.access_active ? 'Con acceso' : 'Sin acceso'}
                        </Chip>
                    </div>
                </td>
            {/snippet}
        </Table>
    {/if}
</div>
