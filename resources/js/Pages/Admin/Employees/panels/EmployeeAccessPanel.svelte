<script lang="ts">
    import { Button, Card, Chip, InfoItem } from '@lumi-ui/svelte';

    interface Props {
        login: string | null;
        accessActive: boolean;
        lastLoginAt: string | null;
        canManage: boolean;
        onChangePassword: () => void;
        onToggleAccess: () => void;
        togglingAccess: boolean;
    }

    const {
        login,
        accessActive,
        lastLoginAt,
        canManage,
        onChangePassword,
        onToggleAccess,
        togglingAccess,
    }: Props = $props();

    const lastLoginLabel = $derived(
        lastLoginAt
            ? new Date(lastLoginAt).toLocaleString('es-PE')
            : 'Sin ingresos registrados',
    );
</script>

<Card spaced>
    <div class="lumi-stack lumi-stack--md">
        <InfoItem icon="user" label="Login" value={login ?? '—'} />
        <InfoItem icon="clock" label="Último acceso" value={lastLoginLabel} />
        <div class="lumi-flex lumi-align-items--center lumi-flex--gap-sm">
            <span class="lumi-text--sm lumi-text--muted">Estado de acceso</span>
            <Chip color={accessActive ? 'success' : 'secondary'} size="sm">
                {accessActive ? 'Habilitado' : 'Deshabilitado'}
            </Chip>
        </div>

        {#if canManage}
            <div class="lumi-flex lumi-flex--gap-sm lumi-flex--wrap">
                <Button type="button" variant="border" icon="key" onclick={onChangePassword}>
                    Cambiar contraseña
                </Button>
                <Button
                    type="button"
                    variant="border"
                    color={accessActive ? 'danger' : 'success'}
                    icon="lock"
                    loading={togglingAccess}
                    onclick={onToggleAccess}
                >
                    {accessActive ? 'Deshabilitar acceso' : 'Habilitar acceso'}
                </Button>
            </div>
        {/if}
    </div>
</Card>
