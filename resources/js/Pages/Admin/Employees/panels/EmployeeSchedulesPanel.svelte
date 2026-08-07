<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import {
        Alert,
        Button,
        Card,
        EmptyState,
        Fieldset,
        InfoItem,
        Input,
        List,
        ListItem,
        Select,
        type SelectOption,
    } from '@lumi-ui/svelte';
    import type { EmployeeScheduleItem } from '@/types/employee-attendance';

    interface Props {
        employeeCode: string;
        active?: boolean;
        schedules: EmployeeScheduleItem[];
        weekdayOptions: SelectOption[];
        currentBranch: { code: string; name: string } | null;
        canManage?: boolean;
    }

    const {
        employeeCode,
        active = false,
        schedules,
        weekdayOptions,
        currentBranch,
        canManage = false,
    }: Props = $props();

    let scheduleError = $state('');
    let scheduleWeekday = $state('1');
    let scheduleEntryTime = $state('07:00');
    let scheduleToTime = $state('09:00');
    let scheduleEditingCode = $state<string | null>(null);
    let processing = $state(false);

    const scheduleSummary = $derived(
        schedules.length === 0
            ? canManage
                ? 'Sin horarios en esta sede. Agrega el primero con el formulario.'
                : 'No hay horarios definidos en la sede actual.'
            : `${schedules.length} horario${schedules.length === 1 ? '' : 's'} en esta sede`,
    );

    function loadScheduleForEdit(schedule: EmployeeScheduleItem): void {
        if (!canManage) return;
        scheduleEditingCode = schedule.code;
        scheduleWeekday = String(schedule.weekday);
        scheduleEntryTime = schedule.entry_time;
        scheduleToTime = schedule.to_time;
        scheduleError = '';
    }

    function cancelScheduleEdit(): void {
        scheduleEditingCode = null;
        scheduleWeekday = '1';
        scheduleEntryTime = '07:00';
        scheduleToTime = '09:00';
        scheduleError = '';
    }

    function saveSchedule(): void {
        if (!canManage || processing || !currentBranch) return;
        router.post(
            `/admin/employees/${employeeCode}/schedules`,
            {
                schedule_code: scheduleEditingCode,
                weekday: Number(scheduleWeekday),
                entry_time: scheduleEntryTime,
                to_time: scheduleToTime,
            },
            {
                preserveScroll: true,
                onStart: () => {
                    processing = true;
                    scheduleError = '';
                },
                onError: (errors) => {
                    scheduleError =
                        errors.weekday ??
                        errors.entry_time ??
                        errors.to_time ??
                        errors.schedule_code ??
                        Object.values(errors)[0] ??
                        'No se pudo guardar el horario.';
                },
                onSuccess: () => {
                    cancelScheduleEdit();
                },
                onFinish: () => {
                    processing = false;
                },
            },
        );
    }

    function deleteSchedule(schedule: EmployeeScheduleItem): void {
        if (!canManage || processing) return;
        router.delete(`/admin/employees/${employeeCode}/schedules/${schedule.code}`, {
            preserveScroll: true,
            onStart: () => {
                processing = true;
                scheduleError = '';
            },
            onError: (errors) => {
                scheduleError =
                    errors.schedule_code ??
                    Object.values(errors)[0] ??
                    'No se pudo eliminar el horario.';
            },
            onSuccess: () => {
                if (scheduleEditingCode === schedule.code) {
                    cancelScheduleEdit();
                }
            },
            onFinish: () => {
                processing = false;
            },
        });
    }
</script>

{#if active}
    <Card
        title="Horarios"
        subtitle={canManage
            ? 'Horarios operativos asignados a este usuario'
            : 'Horarios operativos en modo lectura'}
        spaced
    >
        <div class="lumi-stack lumi-stack--md">
            {#if scheduleError}
                <Alert color="danger" closable onclose={() => (scheduleError = '')}>
                    {scheduleError}
                </Alert>
            {/if}

            {#if !currentBranch}
                <Fieldset legend="Horarios disponibles">
                    <EmptyState
                        icon="building2"
                        title="Sin sede activa"
                        description="Configura una sede activa para ver horarios."
                    />
                </Fieldset>
            {:else}
                {#if canManage}
                    <Fieldset legend={scheduleEditingCode ? 'Editar horario' : 'Nuevo horario'}>
                        <form
                            class="lumi-stack lumi-stack--md"
                            onsubmit={(event) => {
                                event.preventDefault();
                                saveSchedule();
                            }}
                        >
                            <div class="lumi-grid lumi-grid--columns-3 lumi-grid--gap-md">
                                <Select
                                    bind:value={scheduleWeekday}
                                    label="Día"
                                    options={weekdayOptions}
                                    clearable={false}
                                />
                                <Input
                                    bind:value={scheduleEntryTime}
                                    label="Desde"
                                    type="time"
                                    required
                                />
                                <Input
                                    bind:value={scheduleToTime}
                                    label="Hasta"
                                    type="time"
                                    required
                                />
                            </div>
                            <div
                                class="lumi-flex lumi-flex--gap-sm lumi-justify--between lumi-align-items--center"
                            >
                                <div>
                                    {#if scheduleEditingCode}
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            color="warning"
                                            size="sm"
                                            onclick={cancelScheduleEdit}
                                        >
                                            Cancelar edición
                                        </Button>
                                    {/if}
                                </div>
                                <Button
                                    type="submit"
                                    size="sm"
                                    icon={scheduleEditingCode ? 'check' : 'plus'}
                                    loading={processing}
                                >
                                    {scheduleEditingCode ? 'Guardar cambios' : 'Agregar horario'}
                                </Button>
                            </div>
                        </form>
                    </Fieldset>
                {/if}

                <Fieldset legend={`Horarios en esta sede (${schedules.length})`}>
                    <InfoItem icon="clock" label="Estado" value={scheduleSummary} />
                    {#if schedules.length === 0}
                        <div class="lumi-text--center lumi-padding--xl lumi-text--muted">
                            No hay horarios definidos para la sede activa.
                        </div>
                    {:else}
                        <List size="sm">
                            {#each schedules as schedule (schedule.code)}
                                <ListItem
                                    title={schedule.label}
                                    icon="clock"
                                    active={scheduleEditingCode === schedule.code}
                                >
                                    {#if canManage}
                                        <Button
                                            type="button"
                                            variant="flat"
                                            size="sm"
                                            icon="edit"
                                            color="info"
                                            aria-label="Cargar horario en el formulario"
                                            onclick={() => loadScheduleForEdit(schedule)}
                                        />
                                        <Button
                                            type="button"
                                            variant="flat"
                                            color="danger"
                                            size="sm"
                                            icon="trash"
                                            aria-label="Eliminar horario"
                                            loading={processing}
                                            onclick={() => deleteSchedule(schedule)}
                                        />
                                    {/if}
                                </ListItem>
                            {/each}
                        </List>
                    {/if}
                </Fieldset>
            {/if}
        </div>
    </Card>
{/if}
