<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import { Alert, Card, InfoItem, PageHeader } from '@lumi-ui/svelte';
    import { can } from '@/lib/permissions';

    const auth = $derived(page.props.auth);
    const employeeName = $derived(
        auth ? `${auth.employee.first_name} ${auth.employee.last_name}` : '',
    );
</script>

<svelte:head>
    <title>Inicio · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title="Inicio"
        subtitle={employeeName ? `Bienvenido, ${employeeName}` : 'Aeduca'}
        icon="house"
        size="xl"
    />

    {#if auth && auth.branches.length > 1 && !auth.current_branch}
        <Alert color="info" title="Selecciona una sede">
            Elige tu espacio de trabajo desde la sección Sedes.
        </Alert>
    {/if}

    {#if auth && can('dashboard.view')}
        <Card title="Sesión autorizada" subtitle="Contexto actual de trabajo" spaced>
            <div class="lumi-grid lumi-grid--responsive lumi-grid--gap-md">
                <InfoItem
                    icon="user"
                    label="Trabajador"
                    value={employeeName}
                />
                <InfoItem
                    icon="shield"
                    label="Rol principal"
                    value={auth.employee.role_name}
                />
                <InfoItem
                    icon="building2"
                    label="Sede actual"
                    value={auth.current_branch?.name ?? 'Sin sede seleccionada'}
                />
            </div>
        </Card>
    {/if}
</div>
