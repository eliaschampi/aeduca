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
        Tabs,
    } from '@lumi-ui/svelte';

    interface Option {
        code: string;
        name: string;
    }

    type CreateTab = 'person' | 'job' | 'access';

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
    let activeTab = $state<CreateTab>('person');
    let processing = $state(false);
    let errors = $state<Record<string, string>>({});

    const roleOptions = $derived(
        roles.map((role) => ({ value: role.code, label: role.name })),
    );

    const tabs = [
        { value: 'person', label: 'Persona', icon: 'user' },
        { value: 'job', label: 'Puesto', icon: 'building2' },
        { value: 'access', label: 'Acceso', icon: 'key' },
    ];

    function toggleBranch(code: string, checked: boolean): void {
        form.branch_codes = checked
            ? [...form.branch_codes, code]
            : form.branch_codes.filter((current) => current !== code);
    }

    function goNext(): void {
        if (activeTab === 'person') activeTab = 'job';
        else if (activeTab === 'job') activeTab = 'access';
    }

    function goPrev(): void {
        if (activeTab === 'access') activeTab = 'job';
        else if (activeTab === 'job') activeTab = 'person';
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
                // Jump to the tab that owns the first error.
                if (
                    errors.first_name ||
                    errors.last_name ||
                    errors.email ||
                    errors.phone
                ) {
                    activeTab = 'person';
                } else if (
                    errors.employee_role_code ||
                    errors.branch_codes ||
                    errors.is_active
                ) {
                    activeTab = 'job';
                } else if (errors.login || errors.password) {
                    activeTab = 'access';
                }
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
        subtitle="Tres pasos claros: persona, puesto (rol + sedes) y acceso."
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
                Usuarios
            </Button>
        {/snippet}
    </PageHeader>

    <Tabs
        bind:value={activeTab}
        {tabs}
        color="primary"
        aria-label="Pasos de registro"
    />

    <form
        class="lumi-stack lumi-stack--lg"
        onsubmit={(event) => {
            event.preventDefault();
            if (activeTab !== 'access') {
                goNext();
                return;
            }
            submit();
        }}
    >
        {#if activeTab === 'person'}
            <Card title="Persona" subtitle="Datos básicos del usuario." spaced>
                <div class="lumi-grid lumi-grid--responsive lumi-grid--gap-md">
                    <Input
                        label="Nombre"
                        placeholder="Ej. María"
                        bind:value={form.first_name}
                        maxlength={100}
                        required
                        danger={!!errors.first_name}
                        dangerText={errors.first_name}
                    />
                    <Input
                        label="Apellido"
                        placeholder="Ej. Quispe"
                        bind:value={form.last_name}
                        maxlength={100}
                        required
                        danger={!!errors.last_name}
                        dangerText={errors.last_name}
                    />
                    <Input
                        label="Correo (opcional)"
                        type="email"
                        placeholder="nombre@correo.com"
                        bind:value={form.email}
                        maxlength={254}
                        danger={!!errors.email}
                        dangerText={errors.email}
                    />
                    <Input
                        label="Teléfono (opcional)"
                        type="tel"
                        placeholder="999 999 999"
                        bind:value={form.phone}
                        maxlength={30}
                        danger={!!errors.phone}
                        dangerText={errors.phone}
                    />
                </div>
            </Card>
        {:else if activeTab === 'job'}
            <Card
                title="Puesto"
                subtitle="El rol trae los permisos del cargo. Asigna al menos una sede."
                spaced
            >
                <div class="lumi-stack lumi-stack--md">
                    <Select
                        label="Rol"
                        placeholder="Selecciona un rol"
                        options={roleOptions}
                        bind:value={form.employee_role_code}
                        error={!!errors.employee_role_code}
                        errorMessage={errors.employee_role_code}
                    />

                    <Fieldset legend="Sedes autorizadas">
                        <div class="lumi-grid lumi-grid--responsive lumi-grid--gap-sm">
                            {#each branches as branch (branch.code)}
                                <Checkbox
                                    label={branch.name}
                                    checked={form.branch_codes.includes(branch.code)}
                                    onchange={(checked) => toggleBranch(branch.code, checked)}
                                />
                            {:else}
                                <p class="lumi-text--sm lumi-text--muted lumi-margin--none">
                                    No hay sedes habilitadas. Crea una sede antes de registrar usuarios.
                                </p>
                            {/each}
                        </div>
                        {#if errors.branch_codes}
                            <span class="lumi-text--sm lumi-text--danger">{errors.branch_codes}</span>
                        {/if}
                    </Fieldset>

                    <Switch bind:checked={form.is_active} label="Usuario activo" />
                </div>
            </Card>
        {:else}
            <Card title="Acceso" subtitle="Credenciales para iniciar sesión." spaced>
                <div class="lumi-grid lumi-grid--responsive lumi-grid--gap-md">
                    <Input
                        label="Usuario"
                        placeholder="ej. mquispe"
                        bind:value={form.login}
                        maxlength={100}
                        required
                        danger={!!errors.login}
                        dangerText={errors.login}
                        descriptionText="Solo minúsculas, números, punto y guion bajo."
                    />
                    <Input
                        label="Contraseña"
                        type="password"
                        placeholder="Mínimo 8 caracteres"
                        bind:value={form.password}
                        maxlength={255}
                        required
                        danger={!!errors.password}
                        dangerText={errors.password}
                        descriptionText="Mínimo 8 caracteres."
                    />
                </div>
            </Card>
        {/if}

        <div class="lumi-flex lumi-justify--between lumi-flex--gap-sm lumi-flex--wrap">
            <div>
                {#if activeTab !== 'person'}
                    <Button type="button" variant="border" icon="arrowLeft" onclick={goPrev}>
                        Atrás
                    </Button>
                {/if}
            </div>
            <div class="lumi-flex lumi-flex--gap-sm">
                <Button
                    type="button"
                    variant="border"
                    onclick={() => router.visit('/admin/employees')}
                >
                    Cancelar
                </Button>
                {#if activeTab !== 'access'}
                    <Button type="submit" icon="arrowRight">
                        Siguiente
                    </Button>
                {:else}
                    <Button type="submit" icon="userPlus" loading={processing}>
                        Crear usuario
                    </Button>
                {/if}
            </div>
        </div>
    </form>
</div>
