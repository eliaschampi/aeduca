<script lang="ts">
    import { untrack } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import {
        Alert,
        Button,
        Card,
        Chip,
        EmptyState,
        Input,
        PageHeader,
        Select,
        Table,
        type SelectOption,
        type SelectValue,
    } from '@lumi-ui/svelte';
    import {
        attendanceColor,
        type StudentAttendanceEnrollmentContext,
        type StudentAttendanceConstancy,
        type StudentAttendanceHistoryRow,
    } from '@/types/attendance';

    interface Props {
        student: {
            code: string;
            full_name: string;
            dni: string;
        };
        enrollments: StudentAttendanceEnrollmentContext[];
        filters: { enrollment: string; shift: string; from: string; to: string };
        is_self?: boolean;
        business_timezone: string;
        history: StudentAttendanceHistoryRow[];
    }

    const {
        student,
        enrollments,
        filters,
        is_self = false,
        business_timezone,
        history,
    }: Props = $props();

    function seedFilters() {
        return { ...filters };
    }

    let form = $state(untrack(seedFilters));
    let constancyGenerating = $state(false);
    let constancyError = $state<string | null>(null);

    const selectedEnrollment = $derived(
        enrollments.find((enrollment) => enrollment.code === form.enrollment) ?? null,
    );
    const enrollmentOptions = $derived<SelectOption[]>(
        enrollments.map((enrollment) => ({
            value: enrollment.code,
            label: `Cód. ${enrollment.roll_code} · ${enrollment.cycle_name} · ${enrollment.branch_name}`,
        })),
    );
    const shiftOptions = $derived<SelectOption[]>(
        selectedEnrollment?.shifts.map((shift) => ({
            value: shift.code,
            label: shift.name,
        })) ?? [],
    );
    const selectedShift = $derived(
        selectedEnrollment?.shifts.find((shift) => shift.code === form.shift) ?? null,
    );
    const filtersChanged = $derived(
        form.enrollment !== filters.enrollment ||
            form.shift !== filters.shift ||
            form.from !== filters.from ||
            form.to !== filters.to,
    );

    const arrivalFormatter = $derived(
        new Intl.DateTimeFormat('es-PE', {
            timeZone: business_timezone,
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        }),
    );
    const dateFormatter = new Intl.DateTimeFormat('es-PE', {
        timeZone: 'UTC',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });

    function visit(): void {
        router.get(
            `/students/${student.code}/attendance`,
            {
                enrollment: form.enrollment,
                shift: form.shift,
                from: form.from,
                to: form.to,
            },
            { preserveScroll: true, replace: true },
        );
    }

    function changeEnrollment(value: SelectValue | null): void {
        if (typeof value !== 'string') return;

        const enrollment = enrollments.find((item) => item.code === value);
        if (!enrollment) return;

        form = {
            enrollment: enrollment.code,
            shift: enrollment.shifts[0]?.code ?? '',
            from: enrollment.default_from,
            to: enrollment.default_to,
        };
        visit();
    }

    async function generateConstancy(): Promise<void> {
        if (!selectedEnrollment || !selectedShift || filtersChanged || constancyGenerating) return;

        const printWindow = window.open('', '_blank');
        if (!printWindow) {
            constancyError =
                'El navegador bloqueó la constancia. Permite las ventanas emergentes e inténtalo nuevamente.';
            return;
        }

        constancyGenerating = true;
        constancyError = null;

        try {
            const { generateStudentAttendancePdf } = await import('@/lib/student-attendance-pdf');
            const payload: StudentAttendanceConstancy = {
                student,
                enrollment: selectedEnrollment,
                shift: selectedShift,
                period: { from: filters.from, to: filters.to },
                business_timezone,
                generated_at: new Date().toISOString(),
                rows: history,
            };
            await generateStudentAttendancePdf(payload, printWindow);
        } catch (error) {
            printWindow.close();
            constancyError =
                error instanceof Error
                    ? error.message
                    : 'No se pudo generar la constancia de asistencia.';
        } finally {
            constancyGenerating = false;
        }
    }

    function formatArrival(value: string | null): string {
        if (!value) return '—';
        try {
            return arrivalFormatter.format(new Date(value));
        } catch {
            return value;
        }
    }

    function formatDate(value: string): string {
        try {
            return dateFormatter.format(new Date(`${value}T00:00:00Z`));
        } catch {
            return value;
        }
    }
</script>

<svelte:head>
    <title>Historial de asistencia · {student.full_name}</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title="Historial de asistencia"
        subtitle={`${student.full_name} · DNI ${student.dni}`}
        icon="calendar"
        size="xl"
    >
        {#snippet actions()}
            {#if selectedEnrollment}
                <Button
                    type="button"
                    variant="border"
                    icon="download"
                    loading={constancyGenerating}
                    disabled={!form.shift || filtersChanged}
                    onclick={() => void generateConstancy()}
                >
                    Constancia
                </Button>
            {/if}
            <Button
                type="button"
                variant="border"
                icon="arrowLeft"
                onclick={() => router.visit(`/students/${student.code}`)}
            >
                {is_self ? 'Mi perfil' : 'Perfil'}
            </Button>
        {/snippet}
    </PageHeader>

    {#if enrollments.length === 0}
        <EmptyState
            icon="graduationCap"
            title="Sin matrículas visibles"
            description={is_self
                ? 'Todavía no tienes una matrícula disponible para consultar asistencia.'
                : 'El alumno no tiene matrículas visibles en la sede actual.'}
        />
    {:else if selectedEnrollment}
        <Card spaced>
            <div class="lumi-stack lumi-stack--md">
                <div
                    class="lumi-filter-summary lumi-filter-summary--compact lumi-filter-summary--split lumi-filter-summary--secondary"
                >
                    <div class="lumi-filter-summary__copy">
                        <p class="lumi-filter-summary__eyebrow">
                            {selectedEnrollment.branch_name} · Código {selectedEnrollment.roll_code}
                        </p>
                        <h2 class="lumi-filter-summary__title">
                            {selectedEnrollment.cycle_name} · {selectedEnrollment.degree_label} · Sección
                            {selectedEnrollment.group_name}
                        </h2>
                        <p class="lumi-filter-summary__subtitle">
                            {formatDate(selectedEnrollment.cycle_start_date)} al
                            {formatDate(selectedEnrollment.cycle_end_date)}
                        </p>
                    </div>
                    <div class="lumi-filter-summary__meta">
                        <Chip
                            size="sm"
                            color={selectedEnrollment.is_active ? 'success' : 'secondary'}
                        >
                            {selectedEnrollment.is_active ? 'Activa' : 'Inactiva'}
                        </Chip>
                        <Chip size="sm" color="secondary">
                            {selectedEnrollment.attendance_includes_saturday
                                ? 'Lun–sáb'
                                : 'Lun–vie'}
                        </Chip>
                    </div>
                </div>

                <form
                    class="lumi-stack lumi-stack--sm"
                    onsubmit={(event) => {
                        event.preventDefault();
                        visit();
                    }}
                >
                    <div class="lumi-inline-filters lumi-inline-filters--compact">
                        <Select
                            label="Matrícula"
                            value={form.enrollment}
                            options={enrollmentOptions}
                            clearable={false}
                            onchange={changeEnrollment}
                        />
                        <Select
                            label="Turno"
                            bind:value={form.shift}
                            options={shiftOptions}
                            clearable={false}
                            disabled={shiftOptions.length === 0}
                        />
                    </div>
                    <div class="lumi-form-action-row lumi-form-action-row--toolbar">
                        <div class="lumi-form-action-row__field">
                            <Input
                                type="date"
                                bind:value={form.from}
                                label="Desde"
                                size="sm"
                                disabled={shiftOptions.length === 0}
                            />
                        </div>
                        <div class="lumi-form-action-row__field">
                            <Input
                                type="date"
                                bind:value={form.to}
                                label="Hasta"
                                size="sm"
                                disabled={shiftOptions.length === 0}
                            />
                        </div>
                        <div class="lumi-form-action-row__actions">
                            <Button type="submit" icon="search" size="sm" disabled={!form.shift}>
                                Consultar
                            </Button>
                        </div>
                    </div>
                </form>
                {#if filtersChanged}
                    <p class="lumi-margin--none lumi-text--sm lumi-text--muted">
                        Consulta el rango antes de generar la constancia.
                    </p>
                {/if}
            </div>
        </Card>

        {#if constancyError}
            <Alert color="danger" closable onclose={() => (constancyError = null)}>
                {constancyError}
            </Alert>
        {/if}

        {#if shiftOptions.length === 0}
            <EmptyState
                icon="clock"
                title="Matrícula sin turno asignado"
                description="Asigna un turno antes de consultar su historial de asistencia."
            />
        {:else if history.length === 0}
            <EmptyState
                icon="calendar"
                title="Sin fechas esperadas en el rango"
                description="Ajusta las fechas dentro del turno seleccionado."
            />
        {:else}
            <Table
                data={history}
                rowKey={(row) =>
                    `${row.enrollment_code}:${row.cycle_shift_code}:${row.attendance_date}`}
                pagination={history.length > 20}
                itemsPerPage={20}
                noDataText="Sin fechas esperadas en el rango."
                aria-label="Historial de asistencia del alumno"
            >
                {#snippet thead()}
                    <th scope="col">Fecha</th>
                    <th scope="col">Estado</th>
                    <th scope="col">Llegada</th>
                    <th scope="col">Motivo</th>
                {/snippet}

                {#snippet row({ row }: { row: StudentAttendanceHistoryRow })}
                    <td><span class="lumi-font--medium">{formatDate(row.attendance_date)}</span></td
                    >
                    <td>
                        <Chip size="sm" color={attendanceColor(row.effective_state)}>
                            {row.state_label}
                        </Chip>
                    </td>
                    <td>{formatArrival(row.arrival_at)}</td>
                    <td>{row.reason ?? '—'}</td>
                {/snippet}
            </Table>
        {/if}
    {/if}
</div>
