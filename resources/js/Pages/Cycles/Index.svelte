<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import {
        Button,
        Card,
        EmptyState,
        PageHeader,
        Progress,
        StatusIndicator,
        Title,
    } from '@lumi-ui/svelte';

    interface CycleSummary {
        code: string;
        name: string;
        modality_label: string;
        start_date: string;
        end_date: string;
        is_active: boolean;
        degrees_count: number;
        groups_count: number;
        timeline: {
            status: 'upcoming' | 'active' | 'completed';
            percentage: number;
            label: string;
        };
    }

    interface Props {
        cycles: CycleSummary[];
        can_manage?: boolean;
    }

    const { cycles, can_manage = false }: Props = $props();
    const dateFormatter = new Intl.DateTimeFormat('es-PE', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

    function formatDateRange(startDate: string, endDate: string): string {
        return dateFormatter.formatRange(
            new Date(`${startDate}T00:00:00`),
            new Date(`${endDate}T00:00:00`),
        );
    }

    function openCycle(code: string): void {
        router.visit(`/admin/cycles/${code}`);
    }

    function progressColor(cycle: CycleSummary): 'primary' | 'info' | 'success' | 'warning' {
        if (cycle.timeline.status === 'completed') return 'success';
        if (cycle.timeline.status === 'upcoming') return 'info';

        return cycle.is_active ? 'primary' : 'warning';
    }
</script>

<svelte:head>
    <title>Ciclos · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title="Ciclos"
        subtitle="Periodos académicos de la sede actual con sus grados, secciones y turnos."
        icon="bookOpen"
        size="xl"
    >
        {#snippet actions()}
            {#if can_manage}
                <Button
                    type="button"
                    icon="plus"
                    onclick={() => router.visit('/admin/cycles/create')}
                >
                    Nuevo ciclo
                </Button>
            {/if}
        {/snippet}
    </PageHeader>

    {#if cycles.length === 0}
        <EmptyState
            icon="bookOpen"
            title="Sin ciclos en esta sede"
            description={can_manage
                ? 'Crea el primer ciclo para configurar grados, secciones y turnos de asistencia.'
                : 'No hay ciclos registrados en la sede actual.'}
        >
            {#snippet actions()}
                {#if can_manage}
                    <Button
                        type="button"
                        icon="plus"
                        onclick={() => router.visit('/admin/cycles/create')}
                    >
                        Crear ciclo
                    </Button>
                {/if}
            {/snippet}
        </EmptyState>
    {:else}
        <div
            class="lumi-grid lumi-grid--cards lumi-grid--gap-md"
            role="list"
            aria-label="Ciclos de la sede"
        >
            {#each cycles as cycle (cycle.code)}
                <div role="listitem">
                    <Card spaced class="lumi-width--full lumi-h--full">
                        <div class="lumi-stack lumi-stack--sm">
                            <div
                                class="lumi-flex lumi-justify--between lumi-align-items--start lumi-flex--gap-md"
                            >
                                <Title size="sm" icon="bookOpen" title={cycle.name} />
                                <div
                                    class="lumi-flex lumi-align-items--center lumi-flex--gap-2xs lumi-text--xs lumi-text--muted"
                                >
                                    <StatusIndicator active={cycle.is_active} />
                                    <span>{cycle.is_active ? 'Activo' : 'Inactivo'}</span>
                                </div>
                            </div>

                            <p class="lumi-margin--none lumi-text--xs lumi-text--muted">
                                <span class="lumi-font--medium">{cycle.modality_label}</span>
                                · {formatDateRange(cycle.start_date, cycle.end_date)}
                            </p>

                            <div class="lumi-stack lumi-stack--xs">
                                <div
                                    class="lumi-flex lumi-align-items--center lumi-justify--between lumi-flex--gap-sm"
                                >
                                    <span
                                        class="lumi-flex-item--grow lumi-min-width--0 lumi-text--sm lumi-text--muted"
                                    >
                                        {cycle.timeline.label}
                                    </span>
                                    <span
                                        class="lumi-flex-item--auto lumi-text--sm lumi-font--medium"
                                    >
                                        {Math.round(cycle.timeline.percentage)}%
                                    </span>
                                </div>

                                <Progress
                                    value={cycle.timeline.percentage}
                                    color={progressColor(cycle)}
                                    size="sm"
                                    aria-label={`Avance de ${cycle.name}: ${cycle.timeline.label}`}
                                />
                            </div>
                        </div>

                        {#snippet footer()}
                            <div
                                class="lumi-flex lumi-justify--between lumi-align-items--center lumi-flex--gap-sm"
                            >
                                <p class="lumi-margin--none lumi-text--sm lumi-text--muted">
                                    {cycle.degrees_count}
                                    {cycle.degrees_count === 1 ? 'grado' : 'grados'} ·
                                    {cycle.groups_count}
                                    {cycle.groups_count === 1 ? 'sección' : 'secciones'}
                                </p>
                                <Button
                                    type="button"
                                    variant="flat"
                                    size="sm"
                                    icon={can_manage ? 'edit' : 'eye'}
                                    color="info"
                                    aria-label={`${can_manage ? 'Editar' : 'Ver'} ${cycle.name}`}
                                    onclick={() => openCycle(cycle.code)}
                                />
                            </div>
                        {/snippet}
                    </Card>
                </div>
            {/each}
        </div>
    {/if}
</div>
