<script lang="ts">
    import { untrack } from 'svelte';
    import {
        Alert,
        Button,
        Card,
        Checkbox,
        Chip,
        EmptyState,
        Fieldset,
        InfoItem,
    } from '@lumi-ui/svelte';

    interface ScopePermission {
        code: string;
        name: string;
        description: string | null;
    }

    interface Props {
        login: string | null;
        accessActive: boolean;
        lastLoginAt: string | null;
        isSuperAdmin: boolean;
        roleName: string | null;
        scope: ScopePermission[];
        selectedCodes: string[];
        canManage: boolean;
        togglingAccess: boolean;
        permissionsProcessing: boolean;
        permissionsError?: string | null;
        onChangePassword: () => void;
        onUpdateAccess: (isActive: boolean) => void;
        onSavePermissions: (codes: string[]) => void;
    }

    let {
        login,
        accessActive,
        lastLoginAt,
        isSuperAdmin,
        roleName,
        scope,
        selectedCodes,
        canManage,
        togglingAccess,
        permissionsProcessing,
        permissionsError = null,
        onChangePassword,
        onUpdateAccess,
        onSavePermissions,
    }: Props = $props();

    let localSelected = $state<string[]>(untrack(() => [...selectedCodes]));

    $effect(() => {
        localSelected = [...selectedCodes];
    });

    const lastLoginLabel = $derived(
        lastLoginAt ? new Date(lastLoginAt).toLocaleString('es-PE') : 'Sin ingresos registrados',
    );

    /** manage implies view in the UI before save. */
    function toggle(code: string, name: string, checked: boolean): void {
        let next = checked ? [...localSelected, code] : localSelected.filter((c) => c !== code);

        if (checked && name.endsWith('.manage')) {
            const viewName = name.replace(/\.manage$/, '.view');
            const view = scope.find((p) => p.name === viewName);
            if (view && !next.includes(view.code)) {
                next = [...next, view.code];
            }
        }

        if (!checked && name.endsWith('.view')) {
            const manageName = name.replace(/\.view$/, '.manage');
            const manage = scope.find((p) => p.name === manageName);
            if (manage) {
                next = next.filter((c) => c !== manage.code);
            }
        }

        localSelected = next;
    }

    function isChecked(code: string): boolean {
        return localSelected.includes(code);
    }

    function isViewLockedByManage(name: string): boolean {
        if (!name.endsWith('.view')) return false;
        const manageName = name.replace(/\.view$/, '.manage');
        const manage = scope.find((p) => p.name === manageName);
        return Boolean(manage && localSelected.includes(manage.code));
    }
</script>

<div class="lumi-stack lumi-stack--lg">
    <Card spaced>
        <div class="lumi-stack lumi-stack--lg">
            <Fieldset legend="Credenciales y acceso">
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
                            <Button
                                type="button"
                                variant="border"
                                icon="key"
                                onclick={onChangePassword}
                            >
                                Cambiar contraseña
                            </Button>
                            <Button
                                type="button"
                                variant="border"
                                color={accessActive ? 'danger' : 'success'}
                                icon="lock"
                                loading={togglingAccess}
                                onclick={() => onUpdateAccess(!accessActive)}
                            >
                                {accessActive ? 'Deshabilitar acceso' : 'Habilitar acceso'}
                            </Button>
                        </div>
                    {/if}
                </div>
            </Fieldset>

            <Fieldset legend="Permisos directos">
                {#if isSuperAdmin}
                    <Alert color="info" closable={false}>
                        Este usuario es superadministrador: tiene acceso completo a todos los
                        permisos del sistema. No se editan grants individuales.
                    </Alert>
                {:else if scope.length === 0}
                    <EmptyState
                        icon="shield"
                        title="Sin alcance en el rol"
                        description={`El rol «${roleName ?? 'sin rol'}» no tiene permisos disponibles para asignar.`}
                    />
                {:else}
                    <form
                        class="lumi-stack lumi-stack--md"
                        onsubmit={(event) => {
                            event.preventDefault();
                            onSavePermissions(localSelected);
                        }}
                    >
                        <p class="lumi-text--sm lumi-text--muted lumi-margin--none">
                            Solo se pueden marcar permisos del alcance del rol
                            {roleName ? `«${roleName}»` : ''}. Marcar «gestionar» incluye
                            automáticamente «ver».
                        </p>

                        {#if permissionsError}
                            <span class="lumi-text--sm lumi-text--danger">{permissionsError}</span>
                        {/if}

                        <div class="lumi-stack lumi-stack--sm">
                            {#each scope as permission (permission.code)}
                                <Checkbox
                                    label={permission.description || permission.name}
                                    checked={isChecked(permission.code)}
                                    disabled={!canManage || isViewLockedByManage(permission.name)}
                                    onchange={(checked) =>
                                        toggle(permission.code, permission.name, checked)}
                                />
                            {/each}
                        </div>

                        {#if canManage}
                            <div class="lumi-flex lumi-justify--end">
                                <Button type="submit" icon="check" loading={permissionsProcessing}>
                                    Guardar permisos
                                </Button>
                            </div>
                        {/if}
                    </form>
                {/if}
            </Fieldset>
        </div>
    </Card>
</div>
