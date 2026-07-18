<script lang="ts">
    import {
        Button,
        Card,
        Checkbox,
        Fieldset,
        Input,
        Select,
        Switch,
    } from '@lumi-ui/svelte';

    interface Option {
        code: string;
        name: string;
    }

    interface FormState {
        first_name: string;
        last_name: string;
        email: string;
        phone: string;
        employee_role_code: string;
        is_active: boolean;
        branch_codes: string[];
    }

    interface Props {
        form: FormState;
        roles: Option[];
        branches: Option[];
        canManage: boolean;
        processing: boolean;
        errors: Record<string, string>;
        onsubmit: () => void;
        onreset: () => void;
    }

    let {
        form = $bindable(),
        roles,
        branches,
        canManage,
        processing,
        errors,
        onsubmit,
        onreset,
    }: Props = $props();

    const roleOptions = $derived(
        roles.map((role) => ({ value: role.code, label: role.name })),
    );

    function toggleBranch(code: string, checked: boolean): void {
        form.branch_codes = checked
            ? [...form.branch_codes, code]
            : form.branch_codes.filter((current) => current !== code);
    }
</script>

<form
    class="lumi-stack lumi-stack--md"
    onsubmit={(event) => {
        event.preventDefault();
        onsubmit();
    }}
>
    <Card spaced>
        <Fieldset legend="Información básica">
            <div class="lumi-stack lumi-stack--md">
                <div class="lumi-grid lumi-grid--columns-2 lumi-grid--gap-md">
                    <Input
                        label="Nombres"
                        bind:value={form.first_name}
                        disabled={!canManage}
                        required
                        danger={Boolean(errors.first_name)}
                        dangerText={errors.first_name}
                    />
                    <Input
                        label="Apellidos"
                        bind:value={form.last_name}
                        disabled={!canManage}
                        required
                        danger={Boolean(errors.last_name)}
                        dangerText={errors.last_name}
                    />
                </div>
                <div class="lumi-grid lumi-grid--columns-2 lumi-grid--gap-md">
                    <Input
                        label="Correo"
                        type="email"
                        bind:value={form.email}
                        disabled={!canManage}
                        danger={Boolean(errors.email)}
                        dangerText={errors.email}
                    />
                    <Input
                        label="Teléfono"
                        bind:value={form.phone}
                        disabled={!canManage}
                        danger={Boolean(errors.phone)}
                        dangerText={errors.phone}
                    />
                </div>
                <Switch
                    bind:checked={form.is_active}
                    label="Usuario activo"
                    disabled={!canManage}
                />
            </div>
        </Fieldset>
    </Card>

    <Card spaced>
        <Fieldset legend="Rol y sedes">
            <div class="lumi-stack lumi-stack--md">
                <Select
                    label="Rol"
                    options={roleOptions}
                    bind:value={form.employee_role_code}
                    disabled={!canManage}
                    error={Boolean(errors.employee_role_code)}
                    errorMessage={errors.employee_role_code}
                />
                <p class="lumi-text--sm lumi-text--muted lumi-margin--none">
                    Al cambiar de rol se quitan permisos directos que no estén en el
                    nuevo alcance.
                </p>
                <div class="lumi-stack lumi-stack--sm">
                    <span class="lumi-text--sm lumi-text--muted">Sedes asignadas</span>
                    {#each branches as branch (branch.code)}
                        <Checkbox
                            label={branch.name}
                            checked={form.branch_codes.includes(branch.code)}
                            disabled={!canManage}
                            onchange={(event) =>
                                toggleBranch(
                                    branch.code,
                                    (event.currentTarget as HTMLInputElement).checked,
                                )}
                        />
                    {/each}
                    {#if errors.branch_codes}
                        <span class="lumi-text--sm lumi-text--danger">{errors.branch_codes}</span>
                    {/if}
                </div>
            </div>
        </Fieldset>
    </Card>

    {#if canManage}
        <div class="lumi-flex lumi-justify--end lumi-flex--gap-sm">
            <Button type="button" variant="border" onclick={onreset}>
                Restablecer
            </Button>
            <Button type="submit" icon="check" loading={processing}>
                Guardar perfil
            </Button>
        </div>
    {/if}
</form>
