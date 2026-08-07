<script lang="ts">
    import { untrack } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import { Alert, Avatar, Button, Card, Dropdown, DropdownItem, Tabs } from '@lumi-ui/svelte';
    import type { SelectOption } from '@lumi-ui/svelte';
    import ProfilePhotoCropper from '@/components/ProfilePhotoCropper.svelte';
    import { can } from '@/lib/permissions';
    import type {
        EmployeeAttendanceHistoryRow,
        EmployeeScheduleItem,
    } from '@/types/employee-attendance';
    import ChangePasswordDialog from '@/Pages/Admin/Employees/panels/ChangePasswordDialog.svelte';
    import EmployeeAccessPanel from '@/Pages/Admin/Employees/panels/EmployeeAccessPanel.svelte';
    import EmployeeAttendancePanel from '@/Pages/Admin/Employees/panels/EmployeeAttendancePanel.svelte';
    import EmployeeGeneralPanel from '@/Pages/Admin/Employees/panels/EmployeeGeneralPanel.svelte';
    import EmployeeSchedulesPanel from '@/Pages/Admin/Employees/panels/EmployeeSchedulesPanel.svelte';

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
        dni: string | null;
        employee_role_code: string;
        role_name: string | null;
        is_active: boolean;
        is_super_admin: boolean;
        branch_codes: string[];
        branches: Option[];
        login: string | null;
        access_active: boolean;
        last_login_at: string | null;
        photo_url: string | null;
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
        dni: string;
        employee_role_code: string;
        is_active: boolean;
        branch_codes: string[];
    }

    type ProfileTab = 'general' | 'attendance' | 'schedules' | 'access';

    interface AttendancePayload {
        filters: { from: string; to: string };
        summary: {
            expected: number;
            present: number;
            late: number;
            absent: number;
            permission: number;
            justified: number;
        };
        history: EmployeeAttendanceHistoryRow[];
        max_days: number;
    }

    interface Props {
        is_self?: boolean;
        active_tab: ProfileTab;
        profile_path: string;
        employee: Employee;
        roles: Option[];
        branches: Option[];
        role_permission_scope?: ScopePermission[];
        permission_codes?: string[];
        can_manage?: boolean;
        can_read_general?: boolean;
        can_edit_photo?: boolean;
        can_manage_schedules?: boolean;
        can_read_schedules?: boolean;
        can_read_attendance?: boolean;
        current_branch?: { code: string; name: string } | null;
        schedules?: EmployeeScheduleItem[];
        weekday_options?: SelectOption[];
        attendance?: AttendancePayload | null;
        card_missing_requirements?: string[];
    }

    const {
        is_self = false,
        active_tab,
        profile_path,
        employee,
        roles,
        branches,
        role_permission_scope = [],
        permission_codes = [],
        can_manage = false,
        can_read_general = false,
        can_edit_photo = false,
        can_manage_schedules = false,
        can_read_schedules = false,
        can_read_attendance = false,
        current_branch = null,
        schedules = [],
        weekday_options = [],
        attendance = null,
        card_missing_requirements = [],
    }: Props = $props();

    const canManage = $derived(can_manage && can('employees.manage'));
    const canEditPhoto = $derived(can_edit_photo);
    const canManageSchedules = $derived(can_manage_schedules && can('employee_attendance.manage'));
    const fullName = $derived(`${employee.first_name} ${employee.last_name}`.trim());
    const branchLabel = $derived(
        employee.branches.map((branch) => branch.name).join(' · ') || 'Sin sedes',
    );
    const showActionsMenu = $derived(
        canEditPhoto || (employee.is_active && (canManage || is_self)) || can_read_general,
    );

    function formFrom(source: Employee): EmployeeForm {
        return {
            first_name: source.first_name,
            last_name: source.last_name,
            email: source.email ?? '',
            phone: source.phone ?? '',
            dni: source.dni ?? '',
            employee_role_code: source.employee_role_code,
            is_active: source.is_active,
            branch_codes: [...source.branch_codes],
        };
    }

    let form = $state(untrack(() => formFrom(employee)));
    let profileProcessing = $state(false);
    let accessToggling = $state(false);
    let permissionsProcessing = $state(false);
    let passwordOpen = $state(false);
    let passwordProcessing = $state(false);
    let photoEditorOpen = $state(false);
    let cardGenerating = $state(false);
    let cardError = $state('');
    let errors = $state<Record<string, string>>({});
    let permissionsError = $state<string | null>(null);
    let passwordError = $state<string | null>(null);

    $effect(() => {
        form = formFrom(employee);
    });

    /** General first; operational and admin tabs follow. */
    const tabs = $derived(
        [
            can_read_general
                ? { value: 'general' as const, label: 'General', icon: 'user' as const }
                : null,
            can_read_attendance
                ? {
                      value: 'attendance' as const,
                      label: 'Asistencia',
                      icon: 'clipboardCheck' as const,
                  }
                : null,
            can_read_schedules
                ? { value: 'schedules' as const, label: 'Horarios', icon: 'clock' as const }
                : null,
            canManage ? { value: 'access' as const, label: 'Acceso', icon: 'key' as const } : null,
        ].filter((tab): tab is NonNullable<typeof tab> => tab !== null),
    );

    function visitTab(tab: string): void {
        const query: Record<string, string> = { tab };
        if (tab === 'attendance' && attendance) {
            query.from = attendance.filters.from;
            query.to = attendance.filters.to;
        }
        router.get(profile_path, query, { preserveScroll: true, replace: true });
    }

    async function generateCard(): Promise<void> {
        if (cardGenerating) return;
        if (card_missing_requirements.length > 0 || !employee.dni || !employee.photo_url) {
            cardError = `Para generar el carnet falta: ${card_missing_requirements.join(' y ')}.`;
            return;
        }
        cardGenerating = true;
        cardError = '';
        try {
            const { generateEmployeeCardPdf } = await import('@/lib/employee-card-pdf');
            await generateEmployeeCardPdf({
                dni: employee.dni,
                first_name: employee.first_name,
                last_name: employee.last_name,
                role_name: employee.role_name,
                photo_url: employee.photo_url,
            });
        } catch (error) {
            cardError = error instanceof Error ? error.message : 'No se pudo generar el carnet.';
        } finally {
            cardGenerating = false;
        }
    }

    function saveProfile(): void {
        if (profileProcessing || !canManage) return;
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
        if (accessToggling || !canManage) return;
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
        if (permissionsProcessing || !canManage) return;
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
        if (passwordProcessing || !canManage) return;
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
    <title>{is_self ? 'Mi perfil' : fullName} · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <!-- Single identity surface (no PageHeader + strip). -->
    <Card spaced>
        <div class="lumi-flex lumi-align-items--center lumi-flex--gap-md lumi-flex--wrap">
            <Avatar
                text={fullName}
                src={employee.photo_url ?? undefined}
                size="xl"
                color={employee.is_super_admin ? 'warning' : 'primary'}
            />
            <div class="lumi-stack lumi-stack--2xs lumi-flex-item--grow lumi-min-width--0">
                <h1 class="lumi-margin--none lumi-text--xl lumi-font--medium lumi-text-ellipsis">
                    {is_self ? 'Mi perfil' : fullName}
                </h1>
                <p class="lumi-margin--none lumi-text--sm lumi-text--muted">
                    {employee.role_name ?? 'Sin rol'}
                    {#if employee.login}
                        · {employee.login}
                    {/if}
                </p>
                <p class="lumi-margin--none lumi-text--sm lumi-text--muted">{branchLabel}</p>
            </div>
            {#if showActionsMenu}
                <Dropdown placement="bottom-end" aria-label="Acciones del perfil">
                    {#snippet triggerContent()}
                        <Button
                            type="button"
                            variant="border"
                            icon="moreVertical"
                            loading={cardGenerating}
                            aria-label="Abrir acciones del perfil"
                        />
                    {/snippet}
                    {#snippet content()}
                        {#if canEditPhoto}
                            <DropdownItem icon="image" onclick={() => (photoEditorOpen = true)}>
                                {employee.photo_url ? 'Cambiar foto' : 'Agregar foto'}
                            </DropdownItem>
                        {/if}
                        {#if employee.is_active && (canManage || is_self)}
                            <DropdownItem icon="creditCard" onclick={() => void generateCard()}>
                                Generar carnet
                            </DropdownItem>
                        {/if}
                        {#if !is_self && can_read_general}
                            <DropdownItem
                                icon="arrowLeft"
                                onclick={() => router.visit('/admin/employees')}
                            >
                                Volver a usuarios
                            </DropdownItem>
                        {/if}
                    {/snippet}
                </Dropdown>
            {/if}
        </div>
    </Card>

    {#if cardError}
        <Alert color="warning" closable onclose={() => (cardError = '')}>{cardError}</Alert>
    {/if}

    <Tabs
        {tabs}
        value={active_tab}
        aria-label="Secciones del perfil"
        onchange={(value) => {
            if (typeof value === 'string' && value !== active_tab) visitTab(value);
        }}
    />

    {#if active_tab === 'general'}
        <EmployeeGeneralPanel
            bind:form
            {roles}
            {branches}
            {canManage}
            processing={profileProcessing}
            {errors}
            isSuperAdmin={employee.is_super_admin}
            isActive={employee.is_active}
            accessActive={employee.access_active}
            onsubmit={saveProfile}
            onreset={resetProfile}
        />
    {:else if active_tab === 'attendance' && can_read_attendance}
        <EmployeeAttendancePanel
            active
            profilePath={profile_path}
            currentBranch={current_branch}
            {attendance}
        />
    {:else if active_tab === 'schedules' && can_read_schedules}
        <EmployeeSchedulesPanel
            employeeCode={employee.code}
            active
            {schedules}
            weekdayOptions={weekday_options}
            currentBranch={current_branch}
            canManage={canManageSchedules}
        />
    {:else if active_tab === 'access' && canManage}
        <EmployeeAccessPanel
            login={employee.login}
            accessActive={employee.access_active}
            lastLoginAt={employee.last_login_at}
            isSuperAdmin={employee.is_super_admin}
            roleName={employee.role_name}
            scope={role_permission_scope}
            selectedCodes={permission_codes}
            {canManage}
            togglingAccess={accessToggling}
            {permissionsProcessing}
            {permissionsError}
            onChangePassword={() => (passwordOpen = true)}
            onUpdateAccess={updateAccess}
            onSavePermissions={savePermissions}
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

{#if canEditPhoto}
    <ProfilePhotoCropper
        bind:open={photoEditorOpen}
        uploadUrl={`/admin/employees/${employee.code}/photo`}
        subjectName={fullName}
        fileName="foto-usuario.webp"
    />
{/if}
