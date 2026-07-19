<script lang="ts">
    import { untrack } from 'svelte';
    import { Alert, Button, Card, Checkbox, EmptyState, Fieldset } from '@lumi-ui/svelte';

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
        canManage,
        processing,
        error = null,
        onsave,
    }: Props = $props();

    let localSelected = $state<string[]>(untrack(() => [...selectedCodes]));

    $effect(() => {
        localSelected = [...selectedCodes];
    });

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
                        {roleName ? `«${roleName}»` : ''}. Marcar «gestionar» incluye
                        automáticamente «ver».
                    </p>

                    {#if error}
                        <span class="lumi-text--sm lumi-text--danger">{error}</span>
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
