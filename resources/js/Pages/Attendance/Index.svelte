<script lang="ts">
    import { untrack } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import {
        Button,
        Card,
        Chip,
        Dropdown,
        DropdownItem,
        EmptyState,
        Input,
        PageHeader,
        PageSidebar,
        Select,
        Table,
        type SelectOption,
    } from '@lumi-ui/svelte';
    import AttendanceManualDialog from './components/AttendanceManualDialog.svelte';
    import {
        attendanceColor,
        type AttendanceRow,
        type AttendanceSummary,
    } from '@/types/attendance';

    interface CatalogGroup {
        code: string;
        name: string;
    }

    interface CatalogDegree {
        number: number;
        label: string;
        groups: CatalogGroup[];
    }

    interface CatalogShift {
        code: string;
        name: string;
    }

    interface CatalogCycle {
        code: string;
        name: string;
        degrees: CatalogDegree[];
        shifts: CatalogShift[];
    }

    interface Props {
        attendance: {
            data: AttendanceRow[];
            current_page: number;
            last_page: number;
            total: number;
        };
        summary: AttendanceSummary;
        filters: {
            date: string;
            cycle: string;
            degree: string;
            group: string;
            shift: string;
            q: string;
        };
        context_complete?: boolean;
        catalog: CatalogCycle[];
        business_timezone: string;
        can_manage?: boolean;
        can_view_profiles?: boolean;
    }

    const {
        attendance,
        summary,
        filters,
        context_complete = false,
        catalog,
        business_timezone,
        can_manage = false,
        can_view_profiles = false,
    }: Props = $props();

    function seedFilters() {
        return { ...filters };
    }

    let form = $state(untrack(seedFilters));
    let filtersOpen = $state(false);
    let selectedRow = $state<AttendanceRow | null>(null);
    let manualOpen = $state(false);

    const arrivalFormatter = $derived(
        new Intl.DateTimeFormat('es-PE', {
            timeZone: business_timezone,
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false,
        }),
    );

    const selectedCycle = $derived(catalog.find((cycle) => cycle.code === form.cycle) ?? null);
    const selectedDegree = $derived(
        selectedCycle?.degrees.find((degree) => String(degree.number) === form.degree) ?? null,
    );
    const selectedGroup = $derived(
        selectedDegree?.groups.find((group) => group.code === form.group) ?? null,
    );
    const selectedShift = $derived(
        selectedCycle?.shifts.find((shift) => shift.code === form.shift) ?? null,
    );
    const appliedCycle = $derived(catalog.find((cycle) => cycle.code === filters.cycle) ?? null);
    const appliedDegree = $derived(
        appliedCycle?.degrees.find((degree) => String(degree.number) === filters.degree) ?? null,
    );
    const appliedGroup = $derived(
        appliedDegree?.groups.find((group) => group.code === filters.group) ?? null,
    );
    const appliedShift = $derived(
        appliedCycle?.shifts.find((shift) => shift.code === filters.shift) ?? null,
    );
    const selectionComplete = $derived(
        Boolean(selectedCycle && selectedDegree && selectedGroup && selectedShift),
    );
    const contextTitle = $derived(
        selectionComplete
            ? `${selectedDegree?.label} · Sección ${selectedGroup?.name} · ${selectedShift?.name}`
            : 'Selecciona sección y turno',
    );
    const appliedContextTitle = $derived(
        context_complete && appliedDegree && appliedGroup && appliedShift
            ? `${appliedDegree.label} · Sección ${appliedGroup.name} · ${appliedShift.name}`
            : 'Selecciona sección y turno',
    );
    const cycleOptions = $derived<SelectOption[]>(
        catalog.map((cycle) => ({ value: cycle.code, label: cycle.name })),
    );
    const degreeOptions = $derived<SelectOption[]>(
        selectedCycle?.degrees.map((degree) => ({
            value: String(degree.number),
            label: degree.label,
        })) ?? [],
    );
    const groupOptions = $derived<SelectOption[]>(
        selectedDegree?.groups.map((group) => ({
            value: group.code,
            label: group.name,
        })) ?? [],
    );
    const shiftOptions = $derived<SelectOption[]>(
        selectedCycle?.shifts.map((shift) => ({
            value: shift.code,
            label: shift.name,
        })) ?? [],
    );

    function apply(page = 1): void {
        if (!selectionComplete) return;

        router.get(
            '/attendance',
            {
                date: form.date,
                cycle: form.cycle,
                degree: form.degree,
                group: form.group,
                shift: form.shift,
                q: form.q.trim(),
                page,
            },
            { preserveScroll: true, preserveState: true, replace: true },
        );
        filtersOpen = false;
    }

    function changeCycle(value: unknown): void {
        form.cycle = typeof value === 'string' ? value : '';
        form.degree = '';
        form.group = '';
        form.shift = '';
    }

    function changeDegree(value: unknown): void {
        form.degree = typeof value === 'string' ? value : '';
        form.group = '';
    }

    function openProfile(row: AttendanceRow): void {
        if (!can_view_profiles) return;
        router.visit(`/students/${row.student_code}`);
    }

    function openHistory(row: AttendanceRow): void {
        const query = new URLSearchParams({
            enrollment: row.enrollment_code,
            shift: row.cycle_shift_code,
        });

        router.visit(`/students/${row.student_code}/attendance?${query}`);
    }

    function openManual(row: AttendanceRow): void {
        selectedRow = row;
        manualOpen = true;
    }

    function formatArrival(value: string | null): string {
        if (!value) return '—';
        try {
            return arrivalFormatter.format(new Date(value));
        } catch {
            return value;
        }
    }

    function countLabel(count: number, singular: string, plural: string): string {
        return `${count} ${count === 1 ? singular : plural}`;
    }
</script>

<svelte:head>
    <title>Asistencia · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title="Asistencia"
        subtitle="Lista del día en la sede actual. La falta se calcula cuando no hay registro y la ventana ya cerró."
        icon="clipboardCheck"
        size="xl"
    >
        {#snippet actions()}
            <div class="lumi-page-sidebar__header-actions">
                <Button
                    type="button"
                    variant="border"
                    class="lumi-page-sidebar__mobile-trigger"
                    icon="slidersHorizontal"
                    onclick={() => (filtersOpen = true)}
                >
                    Selección
                </Button>
                {#if can_manage}
                    <Button
                        type="button"
                        icon="qrCode"
                        onclick={() => router.visit('/attendance/scan')}
                    >
                        Escanear
                    </Button>
                {/if}
            </div>
        {/snippet}
    </PageHeader>

    <div class="lumi-layout--two-columns lumi-page-sidebar-layout">
        <PageSidebar
            bind:mobileOpen={filtersOpen}
            mobileTitle="Seleccionar contexto"
            mobileAriaLabel="Cerrar selección"
        >
            {#snippet sidebar()}
                <form
                    class="lumi-stack lumi-stack--md"
                    onsubmit={(event) => {
                        event.preventDefault();
                        apply();
                    }}
                >
                    <div class="lumi-filter-summary lumi-filter-summary--compact">
                        <p class="lumi-filter-summary__eyebrow">Asistencia del día</p>
                        <h2 class="lumi-filter-summary__title">{contextTitle}</h2>
                        <p class="lumi-filter-summary__subtitle">
                            {selectedCycle?.name ??
                                'Elige fecha, ciclo, sección y turno para cargar la lista del día.'}
                        </p>
                    </div>

                    <Input bind:value={form.date} type="date" label="Fecha" />
                    <Select
                        value={form.cycle}
                        options={cycleOptions}
                        label="Ciclo"
                        placeholder="Selecciona un ciclo"
                        clearable={false}
                        onchange={changeCycle}
                    />
                    <Select
                        value={form.degree}
                        options={degreeOptions}
                        label="Grado"
                        placeholder="Selecciona un grado"
                        clearable={false}
                        disabled={!form.cycle}
                        onchange={changeDegree}
                    />
                    <Select
                        bind:value={form.group}
                        options={groupOptions}
                        label="Sección"
                        placeholder="Selecciona una sección"
                        clearable={false}
                        disabled={!form.degree}
                    />
                    <Select
                        bind:value={form.shift}
                        options={shiftOptions}
                        label="Turno"
                        placeholder="Selecciona un turno"
                        clearable={false}
                        disabled={!form.cycle}
                    />
                    <Input
                        bind:value={form.q}
                        label="Buscar en la sección"
                        placeholder="Nombre, DNI o código"
                        icon="search"
                        disabled={!selectionComplete}
                    />
                    <Button type="submit" icon="listChecks" disabled={!selectionComplete}>
                        Ver asistencia
                    </Button>
                </form>
            {/snippet}
        </PageSidebar>

        <section class="lumi-layout--content-right lumi-min-width--0">
            <Card spaced>
                <div class="lumi-stack lumi-stack--md">
                    <div
                        class="lumi-filter-summary lumi-filter-summary--split lumi-filter-summary--secondary"
                    >
                        <div class="lumi-filter-summary__copy">
                            <p class="lumi-filter-summary__eyebrow">Esperados del turno</p>
                            <h2 class="lumi-filter-summary__title">{appliedContextTitle}</h2>
                            <p class="lumi-filter-summary__subtitle">
                                {#if context_complete}
                                    {appliedCycle?.name} · {filters.date} ·
                                    {countLabel(summary.expected, 'esperado', 'esperados')}
                                {:else}
                                    Completa la selección para ver la lista del día.
                                {/if}
                            </p>
                        </div>
                        {#if context_complete && summary.expected > 0}
                            <div class="lumi-filter-summary__meta">
                                {#if summary.present > 0}
                                    <Chip color="success" size="sm"
                                        >{countLabel(
                                            summary.present,
                                            'presente',
                                            'presentes',
                                        )}</Chip
                                    >
                                {/if}
                                {#if summary.late > 0}
                                    <Chip color="warning" size="sm"
                                        >{countLabel(summary.late, 'tardanza', 'tardanzas')}</Chip
                                    >
                                {/if}
                                {#if summary.permission > 0}
                                    <Chip color="secondary" size="sm"
                                        >{countLabel(
                                            summary.permission,
                                            'permiso',
                                            'permisos',
                                        )}</Chip
                                    >
                                {/if}
                                {#if summary.justified > 0}
                                    <Chip color="info" size="sm"
                                        >{countLabel(
                                            summary.justified,
                                            'justificado',
                                            'justificados',
                                        )}</Chip
                                    >
                                {/if}
                                {#if summary.absent > 0}
                                    <Chip color="danger" size="sm"
                                        >{countLabel(summary.absent, 'falta', 'faltas')}</Chip
                                    >
                                {/if}
                                {#if summary.pending > 0}
                                    <Chip color="secondary" size="sm"
                                        >{countLabel(
                                            summary.pending,
                                            'pendiente',
                                            'pendientes',
                                        )}</Chip
                                    >
                                {/if}
                            </div>
                        {/if}
                    </div>

                    {#if !context_complete}
                        <EmptyState
                            icon="slidersHorizontal"
                            title="Selecciona el contexto"
                            description="La lista se carga sólo para la sección y turno elegidos."
                        />
                    {:else if attendance.data.length === 0}
                        <EmptyState
                            icon="users"
                            title="Sin alumnos esperados"
                            description={filters.q
                                ? 'No hay coincidencias dentro de esta sección y turno.'
                                : 'No hay matrículas activas con este turno en la sección seleccionada.'}
                        />
                    {:else}
                        <Table
                            data={attendance.data}
                            rowKey={(row) => `${row.enrollment_code}:${row.cycle_shift_code}`}
                            hover={can_view_profiles}
                            pagination={attendance.last_page > 1}
                            paginationMode="server"
                            currentPage={attendance.current_page}
                            totalItems={attendance.total}
                            itemsPerPage={20}
                            onpage-change={apply}
                            onrow-click={can_view_profiles ? openProfile : undefined}
                            noDataText="No hay alumnos esperados."
                            aria-label="Asistencia del turno"
                        >
                            {#snippet thead()}
                                <th scope="col">Alumno</th>
                                <th scope="col">Código</th>
                                <th scope="col">Estado</th>
                                <th scope="col">Llegada</th>
                                <th scope="col">Acciones</th>
                            {/snippet}

                            {#snippet row({ row }: { row: AttendanceRow })}
                                <td>
                                    <div class="lumi-stack lumi-stack--2xs">
                                        <span class="lumi-font--medium">{row.full_name}</span>
                                        <span class="lumi-text--xs lumi-text--muted"
                                            >DNI {row.dni}</span
                                        >
                                        {#if !row.student_is_active}
                                            <Chip color="warning" size="sm">Identidad inactiva</Chip
                                            >
                                        {/if}
                                    </div>
                                </td>
                                <td><strong>{row.roll_code}</strong></td>
                                <td>
                                    <Chip size="sm" color={attendanceColor(row.effective_state)}>
                                        {row.state_label}
                                    </Chip>
                                </td>
                                <td>{formatArrival(row.arrival_at)}</td>
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
                                            <DropdownItem
                                                icon="clipboardCheck"
                                                onclick={() => openHistory(row)}
                                            >
                                                Ver historial
                                            </DropdownItem>
                                            {#if can_manage}
                                                <DropdownItem
                                                    icon="edit"
                                                    onclick={() => openManual(row)}
                                                >
                                                    Gestionar asistencia
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
        </section>
    </div>
</div>

{#if can_manage}
    <AttendanceManualDialog
        bind:open={manualOpen}
        row={selectedRow}
        attendanceDate={filters.date}
        onclose={() => {
            selectedRow = null;
        }}
    />
{/if}
