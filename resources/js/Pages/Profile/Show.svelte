<script lang="ts">
    import { Avatar, Button, Card, Chip, InfoItem, PageHeader } from '@lumi-ui/svelte';
    import ProfilePhotoCropper from '@/components/ProfilePhotoCropper.svelte';

    interface BranchOption {
        code: string;
        name: string;
    }

    interface EmployeeProfile {
        code: string;
        first_name: string;
        last_name: string;
        email: string | null;
        phone: string | null;
        role_name: string | null;
        login: string | null;
        last_login_at: string | null;
        branches: BranchOption[];
        photo_url: string | null;
    }

    interface Props {
        employee: EmployeeProfile;
    }

    const { employee }: Props = $props();

    const fullName = $derived(`${employee.first_name} ${employee.last_name}`.trim());
    const branchLabel = $derived(
        employee.branches.map((branch) => branch.name).join(' · ') || 'Sin sedes asignadas',
    );

    let photoEditorOpen = $state(false);

    const lastLoginLabel = $derived(
        employee.last_login_at
            ? new Date(employee.last_login_at).toLocaleString('es-PE')
            : 'Sin ingresos registrados',
    );
</script>

<svelte:head>
    <title>Mi perfil · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader title="Mi perfil" subtitle="Tu identidad en Aeduca" icon="user" size="lg" />

    <Card spaced>
        <div class="lumi-stack lumi-stack--lg">
            <div class="lumi-flex lumi-align-items--center lumi-flex--gap-md lumi-flex--wrap">
                <Avatar
                    text={fullName}
                    src={employee.photo_url ?? undefined}
                    size="xl"
                    color="primary"
                />
                <div class="lumi-stack lumi-stack--2xs lumi-flex-item--grow lumi-min-width--0">
                    <p
                        class="lumi-font--medium lumi-margin--none lumi-text-ellipsis"
                        title={fullName}
                    >
                        {fullName}
                    </p>
                    {#if employee.role_name}
                        <Chip color="secondary" size="sm">{employee.role_name}</Chip>
                    {/if}
                    <div>
                        <Button
                            type="button"
                            variant="border"
                            size="sm"
                            icon="image"
                            onclick={() => (photoEditorOpen = true)}
                        >
                            {employee.photo_url ? 'Cambiar foto' : 'Agregar foto'}
                        </Button>
                    </div>
                </div>
            </div>

            <div class="lumi-grid lumi-grid--columns-1 lumi-grid--gap-sm">
                <InfoItem label="Usuario" value={employee.login ?? 'Sin usuario'} icon="key" />
                <InfoItem label="Correo" value={employee.email ?? 'Sin correo'} icon="mail" />
                <InfoItem label="Teléfono" value={employee.phone ?? 'Sin teléfono'} icon="phone" />
                <InfoItem label="Sedes" value={branchLabel} icon="building2" />
                <InfoItem label="Último acceso" value={lastLoginLabel} icon="clock" />
            </div>
        </div>
    </Card>
</div>

<ProfilePhotoCropper
    bind:open={photoEditorOpen}
    uploadUrl={`/admin/employees/${employee.code}/photo`}
    subjectName={fullName}
    fileName="foto-usuario.webp"
/>
