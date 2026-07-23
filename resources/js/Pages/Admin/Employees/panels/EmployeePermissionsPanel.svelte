<script lang="ts">
    import { untrack } from 'svelte';
    import { Alert, Button, Card, Checkbox, EmptyState, Fieldset } from '@lumi-ui/svelte';
    import {
        isPermissionRequired,
        togglePermissionCodes,
        type PermissionDependencyMap,
    } from '@/lib/permission-dependencies';

    interface ScopePermission {
        code: string;
        name: string;
        description: string | null;
    }

    interface Props {
        isSuperAdmin: boolean;
        roleName: string | null;
        scope: ScopePermission[];
        selectedCodes: string[];
        permission_dependencies: PermissionDependencyMap;
        canManage: boolean;
        processing: boolean;
        error?: string | null;
        onsave: (codes: string[]) => void;
    }

    let {
        isSuperAdmin,
        roleName,
        scope,
        selectedCodes,
        permission_dependencies,
        canManage,
        processing,
        error = null,
        onsave,
    }: Props = $props();

    let localSelected = $state<string[]>(untrack(() => [...selectedCodes]));

    $effect(() => {
        localSelected = [...selectedCodes];
    });

    function toggle(code: string, checked: boolean): void {
        localSelected = togglePermissionCodes(
            code,
            checked,
            localSelected,
            scope,
            permission_dependencies,
        );
    }

    function isChecked(code: string): boolean {
        return localSelected.includes(code);
    }
</script>

{#if isSuperAdmin}
    <Card spaced>
        <Alert color="info">
            Este usuario es superadministrador: tiene acceso completo a todos los permisos del
            sistema. No se editan grants individuales.
        </Alert>
    </Card>
{:else if scope.length === 0}
    <Card spaced>
        <EmptyState
            icon="shield"
            title="Sin alcance en el rol"
            description={`El rol «${roleName ?? 'sin rol'}» no tiene permisos disponibles para asignar.`}
        />
    </Card>
{:else}
    <form
        class="lumi-stack lumi-stack--md"
        onsubmit={(event) => {
            event.preventDefault();
            onsave(localSelected);
        }}
    >
        <Card spaced>
            <Fieldset legend="Permisos directos del usuario">
                <div class="lumi-stack lumi-stack--md">
                    <p class="lumi-text--sm lumi-text--muted lumi-margin--none">
                        Solo se pueden marcar permisos del alcance del rol
                        {roleName ? `«${roleName}»` : ''}. Las dependencias necesarias se incluyen
                        automáticamente.
                    </p>

                    {#if error}
                        <span class="lumi-text--sm lumi-text--danger">{error}</span>
                    {/if}

                    <div class="lumi-stack lumi-stack--sm">
                        {#each scope as permission (permission.code)}
                            <Checkbox
                                label={permission.description || permission.name}
                                checked={isChecked(permission.code)}
                                disabled={!canManage ||
                                    isPermissionRequired(
                                        permission.name,
                                        localSelected,
                                        scope,
                                        permission_dependencies,
                                    )}
                                onchange={(checked) => toggle(permission.code, checked)}
                            />
                        {/each}
                    </div>
                </div>
            </Fieldset>
        </Card>

        {#if canManage}
            <div class="lumi-flex lumi-justify--end">
                <Button type="submit" icon="check" loading={processing}>Guardar permisos</Button>
            </div>
        {/if}
    </form>
{/if}
