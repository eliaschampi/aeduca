<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { Button, Chip, EmptyState, Table, Title } from '@lumi-ui/svelte';
    import type { EnrollmentProfileData } from '@/types/student';

    interface Props {
        studentCode: string;
        enrollments: EnrollmentProfileData[];
        canManage: boolean;
    }

    const { studentCode, enrollments, canManage }: Props = $props();
    const dateFormatter = new Intl.DateTimeFormat('es-PE', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
    const currencyFormatter = new Intl.NumberFormat('es-PE', {
        style: 'currency',
        currency: 'PEN',
    });

    function formatDate(value: string): string {
        return dateFormatter.format(new Date(value));
    }

    function formatMoney(value: string): string {
        return currencyFormatter.format(Number(value));
    }

    function statusColor(
        status: EnrollmentProfileData['status'],
    ): 'success' | 'secondary' | 'info' {
        if (status === 'active') return 'success';
        if (status === 'completed') return 'info';
        return 'secondary';
    }

    function createEnrollment(): void {
        router.visit(`/students/${studentCode}/enrollments/create`);
    }
</script>

<div class="lumi-stack lumi-stack--md">
    <div
        class="lumi-flex lumi-align-items--center lumi-justify--between lumi-flex--gap-md lumi-flex--wrap"
    >
        <Title
            title="Historial de matrículas"
            subtitle="Asignaciones académicas y obligaciones generadas."
            icon="graduationCap"
            size="sm"
            level={2}
        />
        {#if canManage}
            <Button type="button" size="sm" icon="plus" onclick={createEnrollment}>
                Nueva matrícula
            </Button>
        {/if}
    </div>

    {#if enrollments.length === 0}
        <EmptyState
            icon="graduationCap"
            title="Sin matrículas"
            description="El estudiante todavía no tiene una asignación académica."
        >
            {#snippet actions()}
                {#if canManage}
                    <Button type="button" variant="border" icon="plus" onclick={createEnrollment}>
                        Registrar matrícula
                    </Button>
                {/if}
            {/snippet}
        </EmptyState>
    {:else}
        <Table
            data={enrollments}
            rowKey={(enrollment) => enrollment.code}
            compact
            hover
            noDataText="Sin matrículas"
            aria-label="Historial de matrículas"
        >
            {#snippet thead()}
                <th scope="col">Código</th>
                <th scope="col">Asignación</th>
                <th scope="col">Turnos</th>
                <th scope="col">Obligaciones</th>
                <th scope="col">Estado</th>
                <th scope="col" aria-label="Acciones"></th>
            {/snippet}

            {#snippet row({ row }: { row: EnrollmentProfileData })}
                <td>
                    <div class="lumi-stack lumi-stack--2xs">
                        <span class="lumi-font--medium">{row.roll_code}</span>
                        <span class="lumi-text--xs lumi-text--muted">
                            {formatDate(row.created_at)}
                        </span>
                    </div>
                </td>
                <td>
                    <div class="lumi-stack lumi-stack--2xs">
                        <span class="lumi-font--medium">{row.assignment}</span>
                        <span class="lumi-text--xs lumi-text--muted">
                            {row.cycle_name} · {row.branch_name}
                        </span>
                    </div>
                </td>
                <td>{row.shift_names.join(' + ')}</td>
                <td>
                    <div class="lumi-stack lumi-stack--2xs">
                        <span class="lumi-font--medium">
                            {formatMoney(row.obligations_total)}
                        </span>
                        <span class="lumi-text--xs lumi-text--muted">
                            {row.obligations_count}
                            {row.obligations_count === 1 ? 'concepto' : 'conceptos'}
                        </span>
                    </div>
                </td>
                <td>
                    <Chip color={statusColor(row.status)} size="sm">
                        {row.status_label}
                    </Chip>
                </td>
                <td>
                    {#if canManage && row.can_edit}
                        <Button
                            type="button"
                            variant="flat"
                            size="sm"
                            icon="edit"
                            aria-label={`Editar matrícula ${row.roll_code}`}
                            onclick={() =>
                                router.visit(
                                    `/students/${studentCode}/enrollments/${row.code}/edit`,
                                )}
                        />
                    {/if}
                </td>
            {/snippet}
        </Table>
    {/if}
</div>
