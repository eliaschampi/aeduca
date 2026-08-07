<script lang="ts">
    import { untrack } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import {
        Button,
        Card,
        Chip,
        Dialog,
        EmptyState,
        Input,
        PageHeader,
        RemoteSelect,
        Select,
        Table,
        UserInfo,
        type SelectOption,
        type SelectValue,
    } from '@lumi-ui/svelte';
    import type {
        StudentAttentionBranch,
        StudentAttentionBranchSummary,
        StudentAttentionTypeOption,
    } from '@/types/student-attention';

    interface StudentLookupItem {
        code: string;
        full_name: string;
        dni: string;
    }

    interface Props {
        branch: StudentAttentionBranch;
        attentions: {
            data: StudentAttentionBranchSummary[];
            current_page: number;
            last_page: number;
            total: number;
        };
        filters: { month: string; type: string; q: string };
        type_options: StudentAttentionTypeOption[];
        business_timezone: string;
        can_manage?: boolean;
    }

    const {
        branch,
        attentions,
        filters,
        type_options,
        business_timezone,
        can_manage = false,
    }: Props = $props();

    let form = $state(untrack(() => ({ ...filters })));
    let pickerOpen = $state(false);
    let selectedStudentCode = $state('');
    let selectedStudent = $state<StudentLookupItem | null>(null);

    const typeOptions = $derived<SelectOption[]>([
        { value: '', label: 'Todos los tipos' },
        ...type_options,
    ]);
    const dateFormatter = $derived(
        new Intl.DateTimeFormat('es-PE', {
            timeZone: business_timezone,
            dateStyle: 'medium',
            timeStyle: 'short',
        }),
    );

    function visit(page = 1): void {
        router.get(
            '/student-attentions',
            {
                month: form.month,
                type: form.type || undefined,
                q: form.q.trim() || undefined,
                page,
            },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    }

    function clearFilters(): void {
        form.type = '';
        form.q = '';
        visit();
    }

    function changeType(value: SelectValue | null): void {
        form.type = typeof value === 'string' ? value : '';
    }

    function formatDate(value: string): string {
        try {
            return dateFormatter.format(new Date(value));
        } catch {
            return value;
        }
    }

    function studentLabel(student: StudentLookupItem): string {
        return `${student.full_name} · DNI ${student.dni}`;
    }

    function chooseStudent(student: StudentLookupItem | null): void {
        if (!student) return;

        pickerOpen = false;
        selectedStudentCode = '';
        selectedStudent = null;
        router.visit(`/students/${student.code}/attentions/create`);
    }

    function closePicker(): void {
        pickerOpen = false;
        selectedStudentCode = '';
        selectedStudent = null;
    }
</script>

<svelte:head>
    <title>Atenciones · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title="Atenciones"
        subtitle={`Historial de atenciones de ${branch.name}`}
        icon="clipboardPenLine"
        size="xl"
    >
        {#snippet actions()}
            {#if can_manage}
                <Button type="button" icon="plus" onclick={() => (pickerOpen = true)}>
                    Nueva atención
                </Button>
            {/if}
        {/snippet}
    </PageHeader>

    <Card spaced>
        <form
            class="lumi-stack lumi-stack--sm"
            onsubmit={(event) => {
                event.preventDefault();
                visit();
            }}
        >
            <div class="lumi-grid lumi-grid--columns-3 lumi-grid--gap-md">
                <Input bind:value={form.month} type="month" label="Mes" icon="calendar" />
                <Select
                    value={form.type}
                    options={typeOptions}
                    label="Tipo"
                    onchange={changeType}
                />
                <Input
                    bind:value={form.q}
                    label="Alumno o motivo"
                    placeholder="DNI, nombre o motivo"
                    icon="search"
                />
            </div>
            <div
                class="lumi-flex lumi-flex--wrap lumi-justify--between lumi-align-items--center lumi-flex--gap-sm"
            >
                <p class="lumi-margin--none lumi-text--sm lumi-text--muted">
                    {attentions.total}
                    {attentions.total === 1 ? 'atención encontrada' : 'atenciones encontradas'} en
                    {branch.name}
                </p>
                <div class="lumi-flex lumi-flex--wrap lumi-flex--gap-sm">
                    {#if filters.type || filters.q}
                        <Button
                            type="button"
                            variant="border"
                            icon="x"
                            aria-label="Limpiar filtros"
                            onclick={clearFilters}
                        >
                            Limpiar
                        </Button>
                    {/if}
                    <Button type="submit" icon="search">Buscar</Button>
                </div>
            </div>
        </form>
    </Card>

    {#if attentions.data.length === 0}
        <EmptyState
            icon="clipboardPenLine"
            title="Sin atenciones en esta consulta"
            description="Cambia el mes o los filtros para consultar otro periodo."
        >
            {#snippet actions()}
                {#if can_manage}
                    <Button type="button" icon="plus" onclick={() => (pickerOpen = true)}>
                        Registrar atención
                    </Button>
                {/if}
            {/snippet}
        </EmptyState>
    {:else}
        <Table
            data={attentions.data}
            rowKey={(attention) => attention.code}
            hover
            pagination={attentions.last_page > 1}
            paginationMode="server"
            currentPage={attentions.current_page}
            totalItems={attentions.total}
            itemsPerPage={20}
            onpage-change={visit}
            onrow-click={(attention) =>
                router.visit(`/students/${attention.student_code}/attentions/${attention.code}`)}
            noDataText="No hay atenciones."
            aria-label="Atenciones de la sede"
        >
            {#snippet thead()}
                <th scope="col">Alumno</th>
                <th scope="col">Fecha</th>
                <th scope="col">Tipo</th>
                <th scope="col">Motivo</th>
                <th scope="col">Registrado por</th>
                <th scope="col">Archivos</th>
            {/snippet}

            {#snippet row({ row }: { row: StudentAttentionBranchSummary })}
                <td>
                    <UserInfo
                        name={row.student_first_name}
                        lastName={row.student_last_name}
                        description={`DNI ${row.student_dni}`}
                        avatarSize="sm"
                        avatarColor="primary"
                    />
                </td>
                <td>{formatDate(row.occurred_at)}</td>
                <td><Chip color="secondary" size="sm">{row.type_label}</Chip></td>
                <td><span class="lumi-font--medium">{row.reason}</span></td>
                <td>{row.author_name}</td>
                <td>{row.files_count || '—'}</td>
            {/snippet}
        </Table>
    {/if}
</div>

<Dialog bind:open={pickerOpen} title="Nueva atención" size="sm" onclose={closePicker}>
    <div class="lumi-stack lumi-stack--sm">
        <p class="lumi-margin--none lumi-text--sm lumi-text--muted">
            Busca un alumno con matrícula registrada en {branch.name}.
        </p>
        <RemoteSelect
            bind:value={selectedStudentCode}
            bind:selected={selectedStudent}
            endpoint="/student-attentions/students"
            label="Alumno"
            placeholder="Escribe nombre o DNI"
            minQueryLength={2}
            debounceMs={220}
            limit={10}
            noResultsText="Sin alumnos encontrados"
            errorMessageFallback="No se pudo buscar alumnos."
            getOptionValue={(student: StudentLookupItem) => student.code}
            getOptionLabel={studentLabel}
            onchange={chooseStudent}
        />
    </div>

    {#snippet footer()}
        <Button type="button" variant="border" onclick={closePicker}>Cerrar</Button>
    {/snippet}
</Dialog>
