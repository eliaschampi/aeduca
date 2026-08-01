<script lang="ts">
    import { untrack } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import { Button, Card, Chip, EmptyState, Input, PageHeader, Table } from '@lumi-ui/svelte';
    import { attendanceColor, type AttendanceStoredState } from '@/types/attendance';

    interface HistoryRow {
        code: string;
        attendance_date: string;
        state: AttendanceStoredState;
        state_label: string;
        arrival_at: string | null;
        reason: string | null;
        roll_code: string;
        cycle_name: string;
        group_name: string;
        degree_label: string;
        shift_name: string;
        branch_name: string;
    }

    interface Props {
        student: {
            code: string;
            full_name: string;
            dni: string;
        };
        filters: { from: string; to: string };
        is_self?: boolean;
        business_timezone: string;
        history: {
            data: HistoryRow[];
            current_page: number;
            last_page: number;
            total: number;
        };
    }

    const { student, filters, is_self = false, business_timezone, history }: Props = $props();

    function seedFilters() {
        return { ...filters };
    }

    let form = $state(untrack(seedFilters));

    const arrivalFormatter = $derived(
        new Intl.DateTimeFormat('es-PE', {
            timeZone: business_timezone,
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        }),
    );

    function apply(page = 1): void {
        router.get(
            `/students/${student.code}/attendance`,
            {
                from: form.from,
                to: form.to,
                page,
            },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    }

    function formatArrival(value: string | null): string {
        if (!value) return '—';
        try {
            return arrivalFormatter.format(new Date(value));
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

    <Card spaced>
        <form
            class="lumi-inline-filters lumi-inline-filters--compact"
            onsubmit={(event) => {
                event.preventDefault();
                apply();
            }}
        >
            <Input bind:value={form.from} type="date" label="Desde" />
            <Input bind:value={form.to} type="date" label="Hasta" />
            <div class="lumi-inline-filters__actions">
                <Button type="submit" icon="search">Consultar</Button>
            </div>
        </form>
    </Card>

    {#if history.data.length === 0}
        <EmptyState
            icon="calendar"
            title="Sin registros en el rango"
            description="Sólo se listan llegadas y excepciones guardadas. Las faltas derivadas no se materializan."
        />
    {:else}
        <Table
            data={history.data}
            rowKey={(row) => row.code}
            pagination={history.last_page > 1}
            paginationMode="server"
            currentPage={history.current_page}
            totalItems={history.total}
            itemsPerPage={20}
            onpage-change={apply}
            noDataText="Sin registros en el rango."
            aria-label="Historial de asistencia del alumno"
        >
            {#snippet thead()}
                <th scope="col">Fecha</th>
                <th scope="col">Contexto</th>
                <th scope="col">Estado</th>
                <th scope="col">Llegada</th>
                <th scope="col">Motivo</th>
            {/snippet}

            {#snippet row({ row }: { row: HistoryRow })}
                <td>{row.attendance_date}</td>
                <td>
                    <div class="lumi-stack lumi-stack--2xs">
                        <span class="lumi-font--medium">
                            {row.shift_name} · {row.degree_label} · {row.group_name}
                        </span>
                        <span class="lumi-text--xs lumi-text--muted">
                            {row.branch_name} · {row.cycle_name} · Cód. {row.roll_code}
                        </span>
                    </div>
                </td>
                <td>
                    <Chip size="sm" color={attendanceColor(row.state)}>
                        {row.state_label}
                    </Chip>
                </td>
                <td>{formatArrival(row.arrival_at)}</td>
                <td>{row.reason ?? '—'}</td>
            {/snippet}
        </Table>
    {/if}
</div>
