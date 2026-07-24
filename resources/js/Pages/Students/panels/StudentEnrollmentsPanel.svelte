<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { Alert, Button, Card, Chip, EmptyState, Table } from '@lumi-ui/svelte';
    import type { EnrollmentSummary } from '@/types/student';

    interface Props {
        studentCode: string;
        enrollments: EnrollmentSummary[];
        enrollmentCount: number;
        canManage: boolean;
        isSelf: boolean;
    }

    const { studentCode, enrollments, enrollmentCount, canManage, isSelf }: Props = $props();
    const editableEnrollment = $derived(
        enrollments.find((enrollment) => enrollment.status !== 'finalized') ?? null,
    );

    let processingCode = $state<string | null>(null);

    function updateState(enrollment: EnrollmentSummary, isActive: boolean): void {
        if (processingCode) return;

        router.patch(
            `/enrollments/${enrollment.code}/state`,
            { is_active: isActive },
            {
                preserveScroll: true,
                onStart: () => {
                    processingCode = enrollment.code;
                },
                onFinish: () => {
                    processingCode = null;
                },
            },
        );
    }

    function formatDate(value: string): string {
        return new Date(value).toLocaleDateString('es-PE', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        });
    }
</script>

<Card
    title="Matrículas"
    subtitle={isSelf
        ? 'Tu ubicación académica actual e historial visible.'
        : 'Asignación actual e historial académico autorizado.'}
    spaced
>
    <div class="lumi-stack lumi-stack--md">
        {#if canManage}
            <div class="lumi-flex lumi-justify--end">
                <Button
                    type="button"
                    size="sm"
                    icon={editableEnrollment ? 'edit' : 'plus'}
                    onclick={() =>
                        router.visit(
                            editableEnrollment
                                ? `/enrollments/${editableEnrollment.code}/edit`
                                : `/students/${studentCode}/enrollments/create`,
                        )}
                >
                    {editableEnrollment ? 'Editar matrícula' : 'Nueva matrícula'}
                </Button>
            </div>
        {/if}

        {#if enrollmentCount > enrollments.length}
            <Alert color="info">
                Se muestran las {enrollments.length} matrículas más recientes de
                {enrollmentCount}.
            </Alert>
        {/if}

        {#if enrollments.length === 0}
            <EmptyState
                icon="bookOpen"
                title="Sin matrículas visibles"
                description="No hay asignaciones académicas disponibles para este perfil."
            />
        {:else}
            <Table
                data={enrollments}
                rowKey={(enrollment) => enrollment.code}
                hover
                noDataText="Sin matrículas."
                aria-label="Matrículas del alumno"
            >
                {#snippet thead()}
                    <th scope="col">Código</th>
                    <th scope="col">Ubicación académica</th>
                    <th scope="col">Turnos</th>
                    <th scope="col">Estado</th>
                    {#if canManage}<th scope="col">Acciones</th>{/if}
                {/snippet}

                {#snippet row({ row }: { row: EnrollmentSummary })}
                    <td>
                        <div class="lumi-stack lumi-stack--2xs">
                            <strong>{row.roll_code}</strong>
                            <span class="lumi-text--xs lumi-text--muted">
                                {formatDate(row.created_at)}
                            </span>
                        </div>
                    </td>
                    <td>
                        <div class="lumi-stack lumi-stack--2xs">
                            <strong>{row.cycle_name}</strong>
                            <span class="lumi-text--sm lumi-text--muted">
                                {row.degree_label} · Sección {row.group_name} · {row.branch_name}
                            </span>
                        </div>
                    </td>
                    <td>{row.shift_names || '—'}</td>
                    <td>
                        <Chip color={row.status === 'active' ? 'success' : 'secondary'} size="sm">
                            {row.status_label}
                        </Chip>
                    </td>
                    {#if canManage}
                        <td>
                            {#if row.status !== 'finalized'}
                                <div class="lumi-flex lumi-flex--wrap lumi-flex--gap-xs">
                                    <Button
                                        type="button"
                                        variant="flat"
                                        size="sm"
                                        icon="edit"
                                        onclick={() =>
                                            router.visit(`/enrollments/${row.code}/edit`)}
                                    >
                                        Editar
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="flat"
                                        size="sm"
                                        color={row.is_active ? 'danger' : 'success'}
                                        icon={row.is_active ? 'lock' : 'key'}
                                        loading={processingCode === row.code}
                                        onclick={() => updateState(row, !row.is_active)}
                                    >
                                        {row.is_active ? 'Desactivar' : 'Activar'}
                                    </Button>
                                </div>
                            {:else}
                                <span class="lumi-text--sm lumi-text--muted">Sólo lectura</span>
                            {/if}
                        </td>
                    {/if}
                {/snippet}
            </Table>
        {/if}
    </div>
</Card>
