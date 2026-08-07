<script lang="ts">
    import { untrack } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import {
        Button,
        Chip,
        EmptyState,
        PageHeader,
        Select,
        Table,
        type SelectOption,
        type SelectValue,
    } from '@lumi-ui/svelte';
    import type {
        StudentAttentionBranch,
        StudentAttentionSubject,
        StudentAttentionSummary,
        StudentAttentionTypeOption,
    } from '@/types/student-attention';

    interface Props {
        student: StudentAttentionSubject;
        branch: StudentAttentionBranch;
        attentions: {
            data: StudentAttentionSummary[];
            current_page: number;
            last_page: number;
            total: number;
        };
        filters: { type: string };
        type_options: StudentAttentionTypeOption[];
        business_timezone: string;
        can_manage?: boolean;
    }

    const {
        student,
        branch,
        attentions,
        filters,
        type_options,
        business_timezone,
        can_manage = false,
    }: Props = $props();

    let selectedType = $state(untrack(() => filters.type));
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
            `/students/${student.code}/attentions`,
            { type: selectedType, page },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    }

    function changeType(value: SelectValue | null): void {
        selectedType = typeof value === 'string' ? value : '';
        visit();
    }

    function formatDate(value: string): string {
        try {
            return dateFormatter.format(new Date(value));
        } catch {
            return value;
        }
    }
</script>

<svelte:head>
    <title>Atenciones · {student.full_name} · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title="Atenciones"
        subtitle={`${student.full_name} · DNI ${student.dni} · ${branch.name}`}
        icon="clipboardPenLine"
        size="xl"
    >
        {#snippet actions()}
            <div class="lumi-flex lumi-flex--wrap lumi-flex--gap-sm">
                <Button
                    type="button"
                    variant="border"
                    icon="arrowLeft"
                    onclick={() => router.visit(`/students/${student.code}`)}
                >
                    Perfil
                </Button>
                {#if can_manage}
                    <Button
                        type="button"
                        icon="plus"
                        onclick={() => router.visit(`/students/${student.code}/attentions/create`)}
                    >
                        Nueva atención
                    </Button>
                {/if}
            </div>
        {/snippet}
    </PageHeader>

    {#if attentions.total > 0 || filters.type}
        <div class="lumi-inline-filters lumi-inline-filters--compact">
            <Select value={selectedType} options={typeOptions} label="Tipo" onchange={changeType} />
        </div>
    {/if}

    {#if attentions.data.length === 0}
        <EmptyState
            icon="clipboardPenLine"
            title={filters.type ? 'Sin atenciones de este tipo' : 'Sin atenciones registradas'}
            description={filters.type
                ? 'Selecciona otro tipo para consultar el historial.'
                : 'El alumno todavía no tiene atenciones en esta sede.'}
        >
            {#snippet actions()}
                {#if can_manage && !filters.type}
                    <Button
                        type="button"
                        icon="plus"
                        onclick={() => router.visit(`/students/${student.code}/attentions/create`)}
                    >
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
                router.visit(`/students/${student.code}/attentions/${attention.code}`)}
            noDataText="No hay atenciones."
            aria-label="Historial de atenciones"
        >
            {#snippet thead()}
                <th scope="col">Fecha</th>
                <th scope="col">Tipo</th>
                <th scope="col">Motivo</th>
                <th scope="col">Registrado por</th>
                <th scope="col">Archivos</th>
            {/snippet}

            {#snippet row({ row }: { row: StudentAttentionSummary })}
                <td>{formatDate(row.occurred_at)}</td>
                <td><Chip color="secondary" size="sm">{row.type_label}</Chip></td>
                <td><span class="lumi-font--medium">{row.reason}</span></td>
                <td>{row.author_name}</td>
                <td>{row.files_count || '—'}</td>
            {/snippet}
        </Table>
    {/if}
</div>
