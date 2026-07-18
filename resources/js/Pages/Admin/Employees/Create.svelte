<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import {
        Button,
        Card,
        Checkbox,
        Fieldset,
        Input,
        PageHeader,
        Select,
        Switch,
    } from '@lumi-ui/svelte';

    interface Option {
        code: string;
        name: string;
    }

    interface Props {
        roles: Option[];
        branches: Option[];
    }

    const { roles, branches }: Props = $props();

    let form = $state({
        first_name: '',
        last_name: '',
        email: '',
        phone: '',
        employee_role_code: '' as string,
        is_active: true,
        branch_codes: [] as string[],
        login: '',
        password: '',
    });
    let processing = $state(false);
    let errors = $state<Record<string, string>>({});

    const roleOptions = $derived(
        roles.map((role) => ({ value: role.code, label: role.name })),
    );

    function toggleBranch(code: string, checked: boolean): void {
        form.branch_codes = checked
            ? [...form.branch_codes, code]
            : form.branch_codes.filter((current) => current !== code);
    }

    function submit(): void {
        if (processing) return;

        router.post('/admin/employees', { ...form }, {
            onStart: () => {
                processing = true;
                errors = {};
            },
            onError: (formErrors: Record<string, string>) => {
                errors = formErrors;
            },
            onFinish: () => {
                processing = false;
            },
        });
    }
</script>

<svelte:head>
    <title>Nuevo usuario · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title="Nuevo usuario"
        subtitle="Datos básicos, rol, sedes y credenciales de acceso."
        icon="userPlus"
        size="xl"
    >
        {#snippet actions()}
            <Button
                type="button"
                variant="border"
                icon="arrowLeft"
                onclick={() => router.visit('/admin/employees')}
            >
                Volver
            </Button>
        {/snippet}
    </PageHeader>

    <form
        class="lumi-stack lumi-stack--lg"
        onsubmit={(event) => {
            event.preventDefault();
            submit();
        }}
    >
        <Card spaced>
            <Fieldset legend="Información básica">
                <div class="lumi-stack lumi-stack--md">
                    <div class="lumi-grid lumi-grid--columns-2 lumi-grid--gap-md">
                        <Input
                            label="Nombres"
                            bind:value={form.first_name}
                            required
                            danger={Boolean(errors.first_name)}
                            dangerText={errors.first_name}
                        />
                        <Input
                            label="Apellidos"
                            bind:value={form.last_name}
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
                            danger={Boolean(errors.email)}
                            dangerText={errors.email}
                        />
                        <Input
                            label="Teléfono"
                            bind:value={form.phone}
                            danger={Boolean(errors.phone)}
                            dangerText={errors.phone}
                        />
                    </div>
                    <Switch bind:checked={form.is_active} label="Usuario activo" />
                </div>
            </Fieldset>
        </Card>

        <Card spaced>
            <Fieldset legend="Rol y sedes">
                <div class="lumi-stack lumi-stack--md">
                    <Select
                        label="Rol"
                        placeholder="Selecciona un rol"
                        options={roleOptions}
                        bind:value={form.employee_role_code}
                        error={Boolean(errors.employee_role_code)}
                        errorMessage={errors.employee_role_code}
                    />
                    <div class="lumi-stack lumi-stack--sm">
                        <span class="lumi-text--sm lumi-text--muted">
                            Sedes de trabajo (al menos una)
                        </span>
                        {#each branches as branch (branch.code)}
                            <Checkbox
                                label={branch.name}
                                checked={form.branch_codes.includes(branch.code)}
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

        <Card spaced>
            <Fieldset legend="Credenciales de acceso">
                <div class="lumi-grid lumi-grid--columns-2 lumi-grid--gap-md">
                    <Input
                        label="Usuario (login)"
                        bind:value={form.login}
                        required
                        danger={Boolean(errors.login)}
                        dangerText={errors.login}
                    />
                    <Input
                        label="Contraseña"
                        type="password"
                        bind:value={form.password}
                        required
                        danger={Boolean(errors.password)}
                        dangerText={errors.password}
                    />
                </div>
            </Fieldset>
        </Card>

        <div class="lumi-flex lumi-justify--end lumi-flex--gap-sm">
            <Button
                type="button"
                variant="border"
                onclick={() => router.visit('/admin/employees')}
            >
                Cancelar
            </Button>
            <Button type="submit" icon="check" loading={processing}>
                Crear usuario
            </Button>
        </div>
    </form>
</div>
