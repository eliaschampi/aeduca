<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import { Button, Card, EmptyState, PageHeader, QuickAccessCard } from '@lumi-ui/svelte';
    import { can } from '@/lib/permissions';

    const auth = $derived(page.props.auth);
    const employeeName = $derived(
        auth ? `${auth.employee.first_name} ${auth.employee.last_name}` : '',
    );
    const needsBranch = $derived(Boolean(auth && auth.branches.length > 0 && !auth.current_branch));
    const noBranches = $derived(Boolean(auth && auth.branches.length === 0));
</script>

<svelte:head>
    <title>Inicio · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--xl lumi-min-width--0">
    <PageHeader
        title="Inicio"
        subtitle={employeeName
            ? `Hola, ${employeeName}. Accesos rápidos de tu espacio de trabajo.`
            : 'Aeduca'}
        icon="house"
        size="xl"
    />

    {#if noBranches}
        <Card spaced>
            <EmptyState
                icon="building2"
                title="Sin sede asignada"
                description="Pide a un administrador que te asigne a una sede habilitada para operar."
            />
        </Card>
    {:else if needsBranch}
        <Card spaced>
            <EmptyState
                icon="mapPin"
                title="Selecciona una sede de trabajo"
                description="Elige la sede activa de tu sesión para continuar con el trabajo diario."
            >
                {#snippet actions()}
                    <Button
                        type="button"
                        icon="building2"
                        onclick={() => router.visit('/branches')}
                    >
                        Ir a sedes
                    </Button>
                {/snippet}
            </EmptyState>
        </Card>
    {:else}
        <div
            class="lumi-grid lumi-grid--columns-3 lumi-grid--gap-md lumi-width--full lumi-min-width--0"
        >
            <!-- Same API as Coedula: plain href. App-level Inertia link delegation handles SPA visit. -->
            <QuickAccessCard
                href="/branches"
                title="Sede de trabajo"
                description={auth?.current_branch
                    ? `Activa: ${auth.current_branch.name}`
                    : 'Cambia la sede de tu sesión.'}
                icon="building2"
                color="secondary"
            />

            {#if can('employees.view')}
                <QuickAccessCard
                    href="/admin/employees"
                    title="Usuarios"
                    description="Personal, roles asignados y acceso."
                    icon="users"
                    color="primary"
                />
            {/if}

            {#if can('roles.view')}
                <QuickAccessCard
                    href="/admin/roles"
                    title="Roles"
                    description="Permisos que podrán asignarse dentro de cada rol."
                    icon="shield"
                    color="success"
                />
            {/if}
        </div>
    {/if}
</div>
