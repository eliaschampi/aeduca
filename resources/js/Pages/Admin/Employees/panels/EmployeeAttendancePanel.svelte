<script lang="ts">
    import { untrack } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import { Card, Chip, DateRangeFilter, EmptyState, Table } from '@lumi-ui/svelte';
    import {
        employeeAttendanceColor,
        formatScheduleWindow,
        type EmployeeAttendanceHistoryRow,
    } from '@/types/employee-attendance';

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
        active?: boolean;
        profilePath: string;
        currentBranch: { code: string; name: string } | null;
        attendance: AttendancePayload | null;
    }

    const { active = false, profilePath, currentBranch, attendance }: Props = $props();

    let form = $state(
        untrack(() => ({
            from: attendance?.filters.from ?? '',
            to: attendance?.filters.to ?? '',
        })),
    );

    $effect(() => {
        if (attendance) {
            form = { from: attendance.filters.from, to: attendance.filters.to };
        }
    });

    const dateFormatter = new Intl.DateTimeFormat('es-PE', {
        timeZone: 'UTC',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });

    function formatDate(value: string): string {
        return dateFormatter.format(new Date(`${value}T00:00:00Z`));
    }

    function applyRange(): void {
        router.get(
            profilePath,
            {
                tab: 'attendance',
                from: form.from || undefined,
                to: form.to || undefined,
            },
            { preserveScroll: true, replace: true },
        );
    }
</script>

{#if active}
    <Card title="Asistencia" subtitle="Historial operativo de ingreso (solo lectura)" spaced>
        <div class="lumi-stack lumi-stack--md">
            {#if !currentBranch}
                <EmptyState
                    icon="building2"
                    title="Sin sede activa"
                    description="Selecciona una sede de trabajo para consultar el historial."
                />
            {:else if !attendance}
                <EmptyState
                    icon="clipboardCheck"
                    title="Sin datos de asistencia"
                    description="No se pudo cargar el historial para esta sede."
                />
            {:else}
                <div
                    class="lumi-filter-summary lumi-filter-summary--compact lumi-filter-summary--split lumi-filter-summary--secondary"
                >
                    <div class="lumi-filter-summary__copy">
                        <p class="lumi-filter-summary__eyebrow">{currentBranch.name}</p>
                        <h2 class="lumi-filter-summary__title">
                            {formatDate(attendance.filters.from)} al {formatDate(
                                attendance.filters.to,
                            )}
                        </h2>
                        <p class="lumi-filter-summary__subtitle">
                            Hasta {attendance.max_days} días por consulta
                        </p>
                    </div>
                    <div class="lumi-filter-summary__meta">
                        <Chip color="success" size="sm">Presentes {attendance.summary.present}</Chip
                        >
                        <Chip color="warning" size="sm">Tarde {attendance.summary.late}</Chip>
                        <Chip color="danger" size="sm">Faltas {attendance.summary.absent}</Chip>
                        <Chip color="info" size="sm">Permiso {attendance.summary.permission}</Chip>
                        <Chip color="secondary" size="sm"
                            >Justificados {attendance.summary.justified}</Chip
                        >
                    </div>
                </div>

                <DateRangeFilter
                    bind:fromValue={form.from}
                    bind:toValue={form.to}
                    applyLabel="Consultar"
                    compact
                    onapply={applyRange}
                />

                {#if attendance.history.length === 0}
                    <EmptyState
                        icon="calendar"
                        title="Sin horarios en este rango"
                        description="No hay franjas de horario esperadas para este usuario en las fechas seleccionadas."
                    />
                {:else}
                    <Table
                        data={attendance.history}
                        rowKey={(row) => `${row.schedule_code}:${row.attendance_date}`}
                        pagination={attendance.history.length > 20}
                        itemsPerPage={20}
                        hover
                        aria-label="Historial de asistencia"
                    >
                        {#snippet thead()}
                            <th scope="col">Fecha</th>
                            <th scope="col">Horario</th>
                            <th scope="col">Ingreso</th>
                            <th scope="col">Estado</th>
                            <th scope="col">Observación</th>
                        {/snippet}
                        {#snippet row({ row }: { row: EmployeeAttendanceHistoryRow })}
                            <td>
                                <span class="lumi-font--medium"
                                    >{formatDate(row.attendance_date)}</span
                                >
                            </td>
                            <td>
                                {formatScheduleWindow(
                                    row.schedule_entry_time,
                                    row.schedule_to_time,
                                )}
                            </td>
                            <td>{row.attendance_entry_time ?? '—'}</td>
                            <td>
                                <Chip
                                    size="sm"
                                    color={employeeAttendanceColor(row.effective_state)}
                                >
                                    {row.state_label}
                                </Chip>
                            </td>
                            <td>{row.attendance_observation ?? '—'}</td>
                        {/snippet}
                    </Table>
                {/if}
            {/if}
        </div>
    </Card>
{/if}
