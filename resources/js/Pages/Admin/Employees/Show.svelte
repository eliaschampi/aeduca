<script lang="ts">
    import { untrack } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import {
        Avatar,
        Button,
        Card,
        Checkbox,
        Chip,
        Dialog,
        Dropdown,
        DropdownItem,
        EmptyState,
        Fieldset,
        InfoItem,
        Input,
        List,
        ListHeader,
        ListItem,
        PageHeader,
        Select,
        Switch,
        Tabs,
    } from '@lumi-ui/svelte';
    import { can } from '@/lib/permissions';

    interface Option {
        code: string;
        name: string;
    }

    interface Employee {
        code: string;
        first_name: string;
        last_name: string;
        email: string | null;
        phone: string | null;
        employee_role_code: string;
        role_name: string | null;
        is_active: boolean;
        branch_codes: string[];
        branches: Option[];
        login: string | null;
        access_active: boolean;
        last_login_at: string | null;
    }

    interface CatalogPermission {
        code: string;
        name: string;
        description: string | null;
    }

    interface PermissionOverride {
        permission_code: string;
        label?: string;
        is_allowed: boolean;
    }

    interface EmployeeForm {
        first_name: string;
        last_name: string;
        email: string;
        phone: string;
        employee_role_code: string;
        is_active: boolean;
        branch_codes: string[];
    }

    type OverrideChoice = 'allow' | 'deny';
    type ProfileTab = 'profile' | 'security';

    interface Props {
        employee: Employee;
        roles: Option[];
        branches: Option[];
        permission_catalog?: CatalogPermission[];
        permission_overrides?: PermissionOverride[];
    }

    const {
        employee,
        roles,
        branches,
        permission_catalog = [],
        permission_overrides = [],
    }: Props = $props();

    const canManage = $derived(can('employees.manage'));
    const fullName = $derived(`${employee.first_name} ${employee.last_name}`.trim());

    function formFrom(source: Employee): EmployeeForm {
        return {
            first_name: source.first_name,
            last_name: source.last_name,
            email: source.email ?? '',
            phone: source.phone ?? '',
            employee_role_code: source.employee_role_code,
            is_active: source.is_active,
            branch_codes: [...source.branch_codes],
        };
    }

    function catalogLabel(item: CatalogPermission): string {
        return item.description?.trim() || item.name;
    }

    let form = $state(untrack(() => formFrom(employee)));
    let exceptions = $state(untrack(() => [...permission_overrides]));
    let activeTab = $state<ProfileTab>('profile');
    let processing = $state(false);
    let overrideProcessing = $state(false);
    let errors = $state<Record<string, string>>({});
    let overrideError = $state<string | null>(null);

    let addPermissionCode = $state<string | null>(null);
    let addMode = $state<OverrideChoice>('allow');

    let passwordDialogOpen = $state(false);
    let newPassword = $state('');
    let passwordProcessing = $state(false);
    let passwordError = $state<string | null>(null);

    const roleOptions = $derived(
        roles.map((role) => ({ value: role.code, label: role.name })),
    );

    const catalogByCode = $derived(
        new Map(permission_catalog.map((item) => [item.code, item])),
    );

    const exceptionCodes = $derived(new Set(exceptions.map((e) => e.permission_code)));

    const addPermissionOptions = $derived(
        permission_catalog
            .filter((item) => !exceptionCodes.has(item.code))
            .map((item) => ({
                value: item.code,
                label: catalogLabel(item),
            })),
    );

    const modeOptions = [
        { value: 'allow', label: 'Forzar permitir' },
        { value: 'deny', label: 'Forzar denegar' },
    ];

    const tabs = $derived([
        { value: 'profile', label: 'Perfil', icon: 'user' },
        {
            value: 'security',
            label: exceptions.length > 0 ? `Seguridad (${exceptions.length})` : 'Seguridad',
            icon: 'shield',
        },
    ]);

    const lastLoginLabel = $derived.by(() => {
        if (!employee.last_login_at) return 'Nunca';
        try {
            return new Date(employee.last_login_at).toLocaleString('es-PE', {
                dateStyle: 'medium',
                timeStyle: 'short',
            });
        } catch {
            return employee.last_login_at;
        }
    });

    const branchNames = $derived(
        employee.branches.map((b) => b.name).join(', ') || 'Sin sedes',
    );

    function toggleBranch(code: string, checked: boolean): void {
        form.branch_codes = checked
            ? [...form.branch_codes, code]
            : form.branch_codes.filter((current) => current !== code);
    }

    function addException(): void {
        if (!addPermissionCode || exceptionCodes.has(addPermissionCode)) return;
        exceptions = [
            ...exceptions,
            { permission_code: addPermissionCode, is_allowed: addMode === 'allow' },
        ];
        addPermissionCode = null;
        addMode = 'allow';
    }

    function removeException(permissionCode: string): void {
        exceptions = exceptions.filter((item) => item.permission_code !== permissionCode);
    }

    function saveProfile(): void {
        if (processing) return;

        router.put(`/admin/employees/${employee.code}`, { ...form }, {
            preserveScroll: true,
            onStart: () => {
                processing = true;
                errors = {};
            },
            onError: (formErrors: Record<string, string>) => {
                errors = formErrors;
            },
            onSuccess: () => {
                form = formFrom(employee);
            },
            onFinish: () => {
                processing = false;
            },
        });
    }

    function toggleAccess(): void {
        router.put(`/admin/employees/${employee.code}/access`, {}, { preserveScroll: true });
    }

    function openPasswordDialog(): void {
        newPassword = '';
        passwordError = null;
        passwordDialogOpen = true;
    }

    function submitPassword(): void {
        if (passwordProcessing) return;

        router.put(
            `/admin/employees/${employee.code}/password`,
            { password: newPassword },
            {
                preserveScroll: true,
                onStart: () => {
                    passwordProcessing = true;
                    passwordError = null;
                },
                onError: (formErrors: Record<string, string>) => {
                    passwordError = formErrors.password ?? null;
                },
                onSuccess: () => {
                    passwordDialogOpen = false;
                    newPassword = '';
                },
                onFinish: () => {
                    passwordProcessing = false;
                },
            },
        );
    }

    function saveOverrides(): void {
        if (overrideProcessing || !canManage) return;

        router.put(
            `/admin/employees/${employee.code}/permission-overrides`,
            {
                overrides: exceptions.map((item) => ({
                    permission_code: item.permission_code,
                    is_allowed: item.is_allowed,
                })),
            },
            {
                preserveScroll: true,
                onStart: () => {
                    overrideProcessing = true;
                    overrideError = null;
                },
                onError: (formErrors: Record<string, string>) => {
                    overrideError =
                        formErrors.overrides ??
                        formErrors.message ??
                        'No se pudieron guardar las excepciones.';
                },
                onSuccess: () => {
                    exceptions = [...permission_overrides];
                },
                onFinish: () => {
                    overrideProcessing = false;
                },
            },
        );
    }
</script>

<svelte:head>
    <title>{fullName} · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title={fullName}
        subtitle={employee.role_name ?? 'Sin rol'}
        icon="userRound"
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
            {#if canManage}
                <Dropdown placement="bottom-end" aria-label="Acciones del usuario">
                    {#snippet triggerContent()}
                        <Button
                            type="button"
                            variant="border"
                            icon="moreVertical"
                            aria-label="Más acciones"
                        />
                    {/snippet}
                    <DropdownItem icon="key" onclick={openPasswordDialog}>
                        Cambiar contraseña
                    </DropdownItem>
                    <DropdownItem
                        icon={employee.access_active ? 'xCircle' : 'checkCircle'}
                        color={employee.access_active ? 'danger' : 'success'}
                        onclick={toggleAccess}
                    >
                        {employee.access_active ? 'Revocar acceso' : 'Restaurar acceso'}
                    </DropdownItem>
                </Dropdown>
            {/if}
        {/snippet}
    </PageHeader>

    <Card spaced>
        <div class="lumi-hero-panel">
            <div class="lumi-hero-panel__identity">
                <Avatar text={fullName} size="xl" color="primary" />
                <div class="lumi-hero-panel__copy">
                    <h2 class="lumi-hero-panel__name">{fullName}</h2>
                    <p class="lumi-hero-panel__subtitle">
                        {employee.login ? `@${employee.login}` : 'Sin usuario'}
                        {#if employee.email}
                            · {employee.email}
                        {/if}
                    </p>
                    {#if employee.phone}
                        <p class="lumi-hero-panel__subtitle">{employee.phone}</p>
                    {/if}
                    <p class="lumi-hero-panel__subtitle">{branchNames}</p>
                </div>
            </div>
            <div class="lumi-hero-panel__stats">
                <Chip color="info" size="sm" icon="shield">
                    {employee.role_name ?? 'Sin rol'}
                </Chip>
                <Chip color={employee.is_active ? 'success' : 'secondary'} size="sm">
                    {employee.is_active ? 'Activo' : 'Inactivo'}
                </Chip>
                <Chip color={employee.access_active ? 'info' : 'secondary'} size="sm" icon="key">
                    {employee.access_active ? 'Con acceso' : 'Sin acceso'}
                </Chip>
            </div>
        </div>
    </Card>

    <Tabs
        bind:value={activeTab}
        {tabs}
        color="primary"
        aria-label="Secciones del usuario"
    />

    {#if activeTab === 'profile'}
        <Card
            title="Perfil"
            subtitle="Rol y sedes definen el puesto. Datos personales opcionales salvo nombre."
            spaced
        >
            <form
                class="lumi-stack lumi-stack--lg"
                onsubmit={(event) => {
                    event.preventDefault();
                    saveProfile();
                }}
            >
                <div class="lumi-grid lumi-grid--responsive lumi-grid--gap-md">
                    <Input
                        label="Nombre"
                        placeholder="Ej. María"
                        bind:value={form.first_name}
                        maxlength={100}
                        required
                        disabled={!canManage}
                        danger={!!errors.first_name}
                        dangerText={errors.first_name}
                    />
                    <Input
                        label="Apellido"
                        placeholder="Ej. Quispe"
                        bind:value={form.last_name}
                        maxlength={100}
                        required
                        disabled={!canManage}
                        danger={!!errors.last_name}
                        dangerText={errors.last_name}
                    />
                    <Input
                        label="Correo (opcional)"
                        type="email"
                        placeholder="nombre@correo.com"
                        bind:value={form.email}
                        maxlength={254}
                        disabled={!canManage}
                        danger={!!errors.email}
                        dangerText={errors.email}
                    />
                    <Input
                        label="Teléfono (opcional)"
                        type="tel"
                        placeholder="999 999 999"
                        bind:value={form.phone}
                        maxlength={30}
                        disabled={!canManage}
                        danger={!!errors.phone}
                        dangerText={errors.phone}
                    />
                </div>

                <Select
                    label="Rol"
                    placeholder="Selecciona un rol"
                    options={roleOptions}
                    bind:value={form.employee_role_code}
                    disabled={!canManage}
                    error={!!errors.employee_role_code}
                    errorMessage={errors.employee_role_code}
                />

                <Fieldset legend="Sedes autorizadas">
                    <div class="lumi-grid lumi-grid--responsive lumi-grid--gap-sm">
                        {#each branches as branch (branch.code)}
                            <Checkbox
                                label={branch.name}
                                checked={form.branch_codes.includes(branch.code)}
                                disabled={!canManage}
                                onchange={(checked) => toggleBranch(branch.code, checked)}
                            />
                        {/each}
                    </div>
                    {#if errors.branch_codes}
                        <span class="lumi-text--sm lumi-text--danger">{errors.branch_codes}</span>
                    {/if}
                </Fieldset>

                <Switch
                    bind:checked={form.is_active}
                    label="Usuario activo"
                    disabled={!canManage}
                />

                {#if canManage}
                    <div class="lumi-flex lumi-justify--end">
                        <Button type="submit" icon="check" loading={processing}>
                            Guardar perfil
                        </Button>
                    </div>
                {/if}
            </form>
        </Card>
    {:else}
        <Card
            title="Seguridad"
            subtitle={`Acceso: menú ⋮ del encabezado. Permisos base: rol “${employee.role_name ?? '—'}”. Aquí solo excepciones.`}
            spaced
        >
            <div class="lumi-stack lumi-stack--lg">
                <div class="lumi-grid lumi-grid--responsive lumi-grid--gap-md">
                    <InfoItem label="Usuario de acceso" value={employee.login ?? '—'} icon="key" />
                    <InfoItem label="Último acceso" value={lastLoginLabel} icon="clock" />
                </div>

                <div class="lumi-stack lumi-stack--md">
                    <h3 class="lumi-text--sm lumi-text--muted lumi-margin--none">
                        Excepciones de permisos
                    </h3>

                    {#if overrideError}
                        <span class="lumi-text--sm lumi-text--danger">{overrideError}</span>
                    {/if}

                    {#if exceptions.length === 0}
                        <EmptyState
                            icon="shield"
                            title="Sin excepciones"
                            description="Usa solo los permisos del rol. Cambia el rol o edítalo si el cargo completo debe cambiar."
                        />
                    {:else}
                        <List size="sm" maxHeight="md">
                            <ListHeader title="Excepciones activas" icon="slidersHorizontal" />
                            {#each exceptions as item (item.permission_code)}
                                {@const catalog = catalogByCode.get(item.permission_code)}
                                <ListItem
                                    title={item.label
                                        ?? (catalog ? catalogLabel(catalog) : item.permission_code)}
                                    subtitle={item.is_allowed
                                        ? 'Forzar permitir'
                                        : 'Forzar denegar'}
                                    icon={item.is_allowed ? 'checkCircle' : 'xCircle'}
                                >
                                    {#if canManage}
                                        <Button
                                            type="button"
                                            variant="flat"
                                            size="sm"
                                            icon="x"
                                            color="danger"
                                            aria-label="Quitar excepción"
                                            onclick={() => removeException(item.permission_code)}
                                        />
                                    {/if}
                                </ListItem>
                            {/each}
                        </List>
                    {/if}

                    {#if canManage}
                        <div
                            class="lumi-grid lumi-grid--responsive lumi-grid--gap-sm lumi-align-items--end"
                        >
                            <Select
                                label="Permiso"
                                placeholder="Selecciona un permiso"
                                options={addPermissionOptions}
                                bind:value={addPermissionCode}
                                clearable
                            />
                            <Select
                                label="Acción"
                                placeholder="Tipo de excepción"
                                options={modeOptions}
                                bind:value={addMode}
                            />
                            <Button
                                type="button"
                                variant="border"
                                icon="plus"
                                disabled={!addPermissionCode}
                                onclick={addException}
                            >
                                Agregar
                            </Button>
                        </div>

                        <div class="lumi-flex lumi-justify--end">
                            <Button
                                type="button"
                                icon="check"
                                loading={overrideProcessing}
                                onclick={saveOverrides}
                            >
                                Guardar excepciones
                            </Button>
                        </div>
                    {/if}
                </div>
            </div>
        </Card>
    {/if}
</div>

<Dialog
    open={passwordDialogOpen}
    title="Cambiar contraseña"
    size="sm"
    onclose={() => (passwordDialogOpen = false)}
>
    <form
        class="lumi-stack lumi-stack--md"
        onsubmit={(event) => {
            event.preventDefault();
            submitPassword();
        }}
    >
        <Input
            label="Nueva contraseña"
            type="password"
            placeholder="Mínimo 8 caracteres"
            bind:value={newPassword}
            maxlength={255}
            required
            danger={passwordError !== null}
            dangerText={passwordError ?? undefined}
            descriptionText="Mínimo 8 caracteres."
        />

        <div class="lumi-flex lumi-justify--end lumi-flex--gap-sm">
            <Button
                type="button"
                variant="border"
                onclick={() => (passwordDialogOpen = false)}
            >
                Cancelar
            </Button>
            <Button type="submit" icon="check" loading={passwordProcessing}>
                Actualizar
            </Button>
        </div>
    </form>
</Dialog>
