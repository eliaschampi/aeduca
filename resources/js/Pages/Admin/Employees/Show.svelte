<script lang="ts">
    import { untrack } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import { Avatar, Button, Chip, PageHeader, Tabs } from '@lumi-ui/svelte';
    import { can } from '@/lib/permissions';
    import ChangePasswordDialog from './panels/ChangePasswordDialog.svelte';
    import EmployeeAccessPanel from './panels/EmployeeAccessPanel.svelte';
    import EmployeeGeneralPanel from './panels/EmployeeGeneralPanel.svelte';
    import EmployeePermissionsPanel from './panels/EmployeePermissionsPanel.svelte';

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
        is_super_admin: boolean;
        branch_codes: string[];
        branches: Option[];
        login: string | null;
        access_active: boolean;
        last_login_at: string | null;
    }

    interface ScopePermission {
        code: string;
        name: string;
        description: string | null;
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

    type ProfileTab = 'general' | 'access' | 'permissions';

    interface Props {
        employee: Employee;
        roles: Option[];
        branches: Option[];
        role_permission_scope?: ScopePermission[];
        permission_codes?: string[];
        can_manage?: boolean;
    }

    const {
        employee,
        roles,
        branches,
        role_permission_scope = [],
        permission_codes = [],
        can_manage = false,
    }: Props = $props();

    const canManage = $derived(can_manage && can('employees.manage'));
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

    let form = $state(untrack(() => formFrom(employee)));
    let activeTab = $state<ProfileTab>('general');
    let profileProcessing = $state(false);
    let accessToggling = $state(false);
    let permissionsProcessing = $state(false);
    let passwordOpen = $state(false);
    let passwordProcessing = $state(false);
    let errors = $state<Record<string, string>>({});
    let permissionsError = $state<string | null>(null);
    let passwordError = $state<string | null>(null);

    $effect(() => {
        form = formFrom(employee);
    });

    const tabs = [
        { value: 'general', label: 'General', icon: 'user' },
        { value: 'access', label: 'Acceso', icon: 'key' },
        { value: 'permissions', label: 'Permisos', icon: 'shield' },
    ];

    function saveProfile(): void {
        if (profileProcessing) return;
        router.put(
            `/admin/employees/${employee.code}`,
            { ...form },
            {
                preserveScroll: true,
                onStart: () => {
                    profileProcessing = true;
                    errors = {};
                },
                onError: (formErrors: Record<string, string>) => {
                    errors = formErrors;
                },
                onFinish: () => {
                    profileProcessing = false;
                },
            },
        );
    }

    function resetProfile(): void {
        form = formFrom(employee);
        errors = {};
    }

    function updateAccess(isActive: boolean): void {
        if (accessToggling) return;
        router.put(
            `/admin/employees/${employee.code}/access`,
            { is_active: isActive },
            {
                preserveScroll: true,
                onStart: () => {
                    accessToggling = true;
                },
                onFinish: () => {
                    accessToggling = false;
                },
            },
        );
    }

    function savePermissions(codes: string[]): void {
        if (permissionsProcessing) return;
        router.put(
            `/admin/employees/${employee.code}/permissions`,
            { permission_codes: codes },
            {
                preserveScroll: true,
                onStart: () => {
                    permissionsProcessing = true;
                    permissionsError = null;
                },
                onError: (formErrors: Record<string, string>) => {
                    permissionsError =
                        formErrors.permission_codes ??
                        formErrors.message ??
                        'No se pudieron guardar los permisos.';
                },
                onFinish: () => {
                    permissionsProcessing = false;
                },
            },
        );
    }

    function changePassword(password: string): void {
        if (passwordProcessing) return;
        router.put(
            `/admin/employees/${employee.code}/password`,
            { password },
            {
                preserveScroll: true,
                onStart: () => {
                    passwordProcessing = true;
                    passwordError = null;
                },
                onError: (formErrors: Record<string, string>) => {
                    passwordError =
                        formErrors.password ??
                        formErrors.message ??
                        'No se pudo actualizar la contraseña.';
                },
                onSuccess: () => {
                    passwordOpen = false;
                },
                onFinish: () => {
                    passwordProcessing = false;
                },
            },
        );
    }
</script>

<svelte:head>
    <title>{fullName} · Usuarios · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader title={fullName} subtitle={employee.role_name ?? 'Sin rol'} icon="user" size="xl">
        {#snippet actions()}
            <div class="lumi-flex lumi-align-items--center lumi-flex--gap-sm">
                {#if employee.is_super_admin}
                    <Chip color="warning" size="sm">Superadmin</Chip>
                {/if}
                <Chip color={employee.is_active ? 'success' : 'secondary'} size="sm">
                    {employee.is_active ? 'Activo' : 'Inactivo'}
                </Chip>
                <Button
                    type="button"
                    variant="border"
                    icon="arrowLeft"
                    onclick={() => router.visit('/admin/employees')}
                >
                    Volver
                </Button>
            </div>
        {/snippet}
    </PageHeader>

    <div class="lumi-flex lumi-align-items--center lumi-flex--gap-md">
        <Avatar text={fullName} size="lg" color="primary" />
        <div class="lumi-stack lumi-stack--2xs">
            <span class="lumi-text--sm lumi-text--muted">{employee.login ?? 'Sin login'}</span>
            <span class="lumi-text--sm lumi-text--muted">
                {employee.branches.map((b) => b.name).join(' · ') || 'Sin sedes'}
            </span>
        </div>
    </div>

    <Tabs {tabs} bind:value={activeTab} />

    {#if activeTab === 'general'}
        <EmployeeGeneralPanel
            bind:form
            {roles}
            {branches}
            {canManage}
            processing={profileProcessing}
            {errors}
            onsubmit={saveProfile}
            onreset={resetProfile}
        />
    {:else if activeTab === 'access'}
        <EmployeeAccessPanel
            login={employee.login}
            accessActive={employee.access_active}
            lastLoginAt={employee.last_login_at}
            {canManage}
            togglingAccess={accessToggling}
            onChangePassword={() => (passwordOpen = true)}
            onUpdateAccess={updateAccess}
        />
    {:else}
        <EmployeePermissionsPanel
            isSuperAdmin={employee.is_super_admin}
            roleName={employee.role_name}
            scope={role_permission_scope}
            selectedCodes={permission_codes}
            {canManage}
            processing={permissionsProcessing}
            error={permissionsError}
            onsave={savePermissions}
        />
    {/if}
</div>

{#if canManage}
    <ChangePasswordDialog
        open={passwordOpen}
        processing={passwordProcessing}
        error={passwordError}
        onclose={() => (passwordOpen = false)}
        onsubmit={changePassword}
    />
{/if}
