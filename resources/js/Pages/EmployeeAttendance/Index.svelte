<script lang="ts">
    import { untrack } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import {
        Alert,
        Button,
        Card,
        Chip,
        Dialog,
        Dropdown,
        DropdownItem,
        EmptyState,
        Fieldset,
        Input,
        PageHeader,
        Select,
        Table,
        Textarea,
        type SelectOption,
    } from '@lumi-ui/svelte';
    import {
        EMPLOYEE_ATTENDANCE_STATE_OPTIONS,
        employeeAttendanceColor,
        formatScheduleWindow,
        type EmployeeAttendanceRow,
    } from '@/types/employee-attendance';

    interface Props {
        branch: { code: string; name: string };
        filters: { date: string };
        today: string;
        rows: EmployeeAttendanceRow[];
        business_timezone: string;
        can_manage?: boolean;
        can_view_profiles?: boolean;
    }

    const {
        branch,
        filters,
        today,
        rows,
        business_timezone,
        can_manage = false,
        can_view_profiles = false,
    }: Props = $props();

    let filterDate = $state(untrack(() => filters.date));
    let formOpen = $state(false);
    let formError = $state('');
    let formAttendanceCode = $state('');
    let formScheduleCode = $state('');
    let formState = $state('present');
    let formEntryTime = $state('');
    let formObservation = $state('');
    let selected = $state<EmployeeAttendanceRow | null>(null);
    let deleteOpen = $state(false);
    let deleteTarget = $state<EmployeeAttendanceRow | null>(null);
    let processing = $state(false);

    const isToday = $derived(filters.date === today);
    const recordsArrival = $derived(formState === 'present' || formState === 'late');
    const registeredCount = $derived(rows.filter((row) => row.attendance_code).length);
    const stateOptions = $derived<SelectOption[]>(
        EMPLOYEE_ATTENDANCE_STATE_OPTIONS.map((option) => ({
            value: option.value,
            label: option.label,
        })),
    );

    const dateLabel = $derived(
        filters.date === today
            ? 'Hoy'
            : new Intl.DateTimeFormat('es-PE', {
                  timeZone: 'UTC',
                  day: '2-digit',
                  month: 'long',
                  year: 'numeric',
              }).format(new Date(`${filters.date}T00:00:00Z`)),
    );

    const weekdayLabel = $derived(
        new Intl.DateTimeFormat('es-PE', {
            timeZone: 'UTC',
            weekday: 'long',
        }).format(new Date(`${filters.date}T00:00:00Z`)),
    );

    function applyDate(): void {
        router.get(
            '/employee-attendance',
            { date: filterDate || undefined },
            { preserveState: true, replace: true },
        );
    }

    function openProfile(row: EmployeeAttendanceRow): void {
        if (can_view_profiles) router.visit(`/admin/employees/${row.user_code}`);
    }

    function openHistory(row: EmployeeAttendanceRow): void {
        router.visit(`/employee-attendance/employees/${row.user_code}/history`);
    }

    function openEdit(row: EmployeeAttendanceRow): void {
        if (!can_manage || !isToday || !row.attendance_code) return;
        selected = row;
        formAttendanceCode = row.attendance_code;
        formScheduleCode = row.schedule_code;
        formState = row.attendance_state ?? 'present';
        formEntryTime = row.attendance_entry_time ?? '';
        formObservation = row.attendance_observation ?? '';
        formError = '';
        formOpen = true;
    }

    function openCreate(row: EmployeeAttendanceRow): void {
        if (!can_manage || !isToday || row.attendance_code) return;
        selected = row;
        formAttendanceCode = '';
        formScheduleCode = row.schedule_code;
        formState = 'present';
        formEntryTime = row.schedule_entry_time;
        formObservation = '';
        formError = '';
        formOpen = true;
    }

    function closeForm(): void {
        if (processing) return;
        formOpen = false;
        selected = null;
    }

    function submitForm(): void {
        if (!selected || processing) return;
        const isUpdate = Boolean(formAttendanceCode);
        router.post(
            '/employee-attendance/manual',
            {
                operation: isUpdate ? 'update' : 'create',
                attendance_code: isUpdate ? formAttendanceCode : null,
                schedule_code: isUpdate ? null : formScheduleCode,
                state: formState,
                entry_time: recordsArrival ? formEntryTime : null,
                observation: formObservation || null,
            },
            {
                preserveScroll: true,
                onStart: () => {
                    processing = true;
                    formError = '';
                },
                onError: (errors) => {
                    formError =
                        errors.operation ??
                        errors.state ??
                        errors.entry_time ??
                        Object.values(errors)[0] ??
                        'No se pudo guardar.';
                },
                onSuccess: () => {
                    formOpen = false;
                    selected = null;
                },
                onFinish: () => {
                    processing = false;
                },
            },
        );
    }

    function openDelete(row: EmployeeAttendanceRow): void {
        if (!can_manage || !isToday || !row.attendance_code) return;
        deleteTarget = row;
        deleteOpen = true;
    }

    function confirmDelete(): void {
        if (!deleteTarget?.attendance_code || processing) return;
        router.post(
            '/employee-attendance/manual',
            {
                operation: 'delete',
                attendance_code: deleteTarget.attendance_code,
            },
            {
                preserveScroll: true,
                onStart: () => {
                    processing = true;
                },
                onSuccess: () => {
                    deleteOpen = false;
                    deleteTarget = null;
                },
                onFinish: () => {
                    processing = false;
                },
            },
        );
    }

    function scheduleLabel(row: EmployeeAttendanceRow): string {
        return formatScheduleWindow(row.schedule_entry_time, row.schedule_to_time);
    }
</script>

<svelte:head>
    <title>Control horario · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title="Control horario"
        subtitle="Consulta por fecha y edita registros de asistencia de usuarios"
        icon="clipboardCheck"
        size="xl"
    >
        {#snippet actions()}
            {#if can_manage}
                <Button
                    type="button"
                    icon="qrCode"
                    disabled={!isToday}
                    onclick={() => router.visit('/employee-attendance/register')}
                >
                    Registrar
                </Button>
            {/if}
        {/snippet}
    </PageHeader>

    <Card spaced>
        <div class="lumi-stack lumi-stack--md">
            <div class="lumi-flex lumi-flex--gap-sm lumi-align-items--end">
                <div class="lumi-flex-item--grow">
                    <Input
                        bind:value={filterDate}
                        label="Fecha"
                        type="date"
                        icon="calendar"
                        oninput={() => applyDate()}
                    />
                </div>
            </div>

            <p class="lumi-margin--none lumi-text--sm lumi-text--muted">
                {weekdayLabel} · {dateLabel} · {rows.length}
                {rows.length === 1 ? 'horario esperado' : 'horarios esperados'} · {registeredCount}
                {registeredCount === 1 ? 'registro' : 'registros'}
                {#if isToday}
                    · Registro disponible desde Registrar
                {:else}
                    · Solo lectura
                {/if}
                · {branch.name}
            </p>

            {#if rows.length === 0}
                <EmptyState
                    icon="clipboardCheck"
                    title="Sin horarios para esta vista"
                    description="No hay horarios configurados para la sede y fecha seleccionada."
                >
                    {#snippet actions()}
                        {#if can_manage && isToday}
                            <Button
                                type="button"
                                icon="qrCode"
                                onclick={() => router.visit('/employee-attendance/register')}
                            >
                                Registrar asistencia
                            </Button>
                        {/if}
                    {/snippet}
                </EmptyState>
            {:else}
                <Table
                    data={rows}
                    rowKey={(row) => row.schedule_code}
                    hover
                    pagination={rows.length > 20}
                    itemsPerPage={20}
                    aria-label="Control horario del personal"
                >
                    {#snippet thead()}
                        <th scope="col">Usuario</th>
                        <th scope="col">Horario</th>
                        <th scope="col">Ingreso</th>
                        <th scope="col">Estado</th>
                        <th scope="col">Observación</th>
                        <th scope="col">Acciones</th>
                    {/snippet}
                    {#snippet row({ row }: { row: EmployeeAttendanceRow })}
                        <td>
                            <div class="lumi-stack lumi-stack--2xs">
                                <span class="lumi-font--medium">{row.full_name}</span>
                                <span class="lumi-text--xs lumi-text--muted">
                                    {row.dni ? `DNI ${row.dni}` : 'Sin DNI'} · {row.role_name ??
                                        'Personal'} · {row.phone || 'Sin teléfono'}
                                </span>
                            </div>
                        </td>
                        <td>
                            <span class="lumi-font--medium">{scheduleLabel(row)}</span>
                        </td>
                        <td>
                            <span class="lumi-font--medium">{row.attendance_entry_time ?? '—'}</span
                            >
                        </td>
                        <td>
                            <Chip size="sm" color={employeeAttendanceColor(row.effective_state)}>
                                {row.state_label}
                            </Chip>
                        </td>
                        <td>
                            <span class="lumi-text--sm lumi-text--muted">
                                {row.attendance_observation || 'Sin observación'}
                            </span>
                        </td>
                        <td>
                            <Dropdown
                                placement="bottom-end"
                                aria-label={`Acciones de ${row.full_name}`}
                            >
                                {#snippet triggerContent()}
                                    <Button
                                        type="button"
                                        variant="flat"
                                        size="sm"
                                        icon="moreVertical"
                                        aria-label={`Abrir acciones de ${row.full_name}`}
                                    />
                                {/snippet}
                                {#snippet content()}
                                    <DropdownItem icon="history" onclick={() => openHistory(row)}>
                                        Ver historial
                                    </DropdownItem>
                                    {#if can_view_profiles}
                                        <DropdownItem icon="user" onclick={() => openProfile(row)}>
                                            Ver perfil
                                        </DropdownItem>
                                    {/if}
                                    {#if can_manage && isToday && !row.attendance_code}
                                        <DropdownItem icon="plus" onclick={() => openCreate(row)}>
                                            Registrar manual
                                        </DropdownItem>
                                    {/if}
                                    {#if can_manage && isToday && row.attendance_code}
                                        <DropdownItem icon="edit" onclick={() => openEdit(row)}>
                                            Editar registro
                                        </DropdownItem>
                                        <DropdownItem
                                            icon="trash"
                                            color="danger"
                                            onclick={() => openDelete(row)}
                                        >
                                            Eliminar registro
                                        </DropdownItem>
                                    {/if}
                                {/snippet}
                            </Dropdown>
                        </td>
                    {/snippet}
                </Table>
            {/if}
        </div>
    </Card>
</div>

<Dialog
    bind:open={formOpen}
    title={formAttendanceCode ? 'Editar control horario' : 'Registrar control horario'}
    size="md"
>
    {#if selected}
        <form
            class="lumi-stack lumi-stack--md"
            onsubmit={(event) => {
                event.preventDefault();
                submitForm();
            }}
        >
            <Fieldset legend="Usuario">
                <div class="lumi-stack lumi-stack--2xs">
                    <strong>{selected.full_name}</strong>
                    <span class="lumi-text--xs lumi-text--muted">
                        {scheduleLabel(selected)} · {filters.date}
                    </span>
                </div>
            </Fieldset>
            <Select
                bind:value={formState}
                label="Estado"
                options={stateOptions}
                clearable={false}
            />
            {#if recordsArrival}
                <Input bind:value={formEntryTime} type="time" label="Hora de ingreso" required />
            {/if}
            <Textarea
                bind:value={formObservation}
                label="Observación"
                rows={3}
                maxlength={1000}
                placeholder="Opcional"
            />
            {#if formError}
                <Alert color="danger">{formError}</Alert>
            {/if}
            <div class="lumi-flex lumi-justify--end lumi-flex--gap-sm">
                <Button type="button" variant="border" disabled={processing} onclick={closeForm}>
                    Cancelar
                </Button>
                <Button type="submit" icon="check" loading={processing}>Guardar</Button>
            </div>
        </form>
    {/if}
</Dialog>

<Dialog bind:open={deleteOpen} title="Eliminar registro" size="sm" persistent={processing}>
    <div class="lumi-stack lumi-stack--md">
        <p class="lumi-margin--none">
            ¿Eliminar el registro de
            <strong>{deleteTarget?.full_name}</strong> para el horario
            {deleteTarget ? scheduleLabel(deleteTarget) : ''}?
        </p>
        <div class="lumi-flex lumi-justify--end lumi-flex--gap-sm">
            <Button
                type="button"
                variant="border"
                disabled={processing}
                onclick={() => {
                    deleteOpen = false;
                    deleteTarget = null;
                }}
            >
                Cancelar
            </Button>
            <Button
                type="button"
                color="danger"
                icon="trash"
                loading={processing}
                onclick={confirmDelete}
            >
                Eliminar
            </Button>
        </div>
    </div>
</Dialog>
