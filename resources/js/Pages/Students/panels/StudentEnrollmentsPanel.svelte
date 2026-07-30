<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import {
        Alert,
        Button,
        Chip,
        DashboardSection,
        Dialog,
        Dropdown,
        DropdownItem,
        EmptyState,
        Table,
    } from '@lumi-ui/svelte';
    import type { EnrollmentSummary } from '@/types/student';

    interface Props {
        studentCode: string;
        enrollments: EnrollmentSummary[];
        enrollmentCount: number;
        canManage: boolean;
        canDelete: boolean;
        isSelf: boolean;
    }

    const { studentCode, enrollments, enrollmentCount, canManage, canDelete, isSelf }: Props =
        $props();
    const currentBranchEnrollment = $derived(
        enrollments.find(
            (enrollment) => enrollment.is_current_branch && enrollment.status !== 'finalized',
        ) ?? null,
    );

    let processingCode = $state<string | null>(null);
    let deleteOpen = $state(false);
    let pendingDelete = $state<EnrollmentSummary | null>(null);

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

    function confirmDelete(enrollment: EnrollmentSummary): void {
        if (!enrollment.is_current_branch || processingCode) return;

        pendingDelete = enrollment;
        deleteOpen = true;
    }

    function closeDelete(): void {
        if (processingCode) return;

        deleteOpen = false;
        pendingDelete = null;
    }

    function remove(): void {
        if (!pendingDelete || processingCode) return;

        const enrollment = pendingDelete;

        router.delete(`/enrollments/${enrollment.code}`, {
            preserveScroll: true,
            onStart: () => {
                processingCode = enrollment.code;
            },
            onSuccess: () => {
                deleteOpen = false;
                pendingDelete = null;
            },
            onFinish: () => {
                processingCode = null;
            },
        });
    }

    function hasActions(enrollment: EnrollmentSummary): boolean {
        return (
            enrollment.is_current_branch &&
            (canDelete || (canManage && enrollment.status !== 'finalized'))
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

<DashboardSection
    title="Matrículas"
    subtitle={isSelf
        ? 'Tu ubicación académica actual e historial visible.'
        : 'Asignación actual e historial académico autorizado.'}
    spaced
>
    {#snippet actions()}
        {#if canManage && !currentBranchEnrollment}
            <Button
                type="button"
                size="sm"
                icon="plus"
                onclick={() => router.visit(`/students/${studentCode}/enrollments/create`)}
            >
                Nueva matrícula
            </Button>
        {/if}
    {/snippet}
    <div class="lumi-stack lumi-stack--md">
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
                    {#if canManage || canDelete}<th scope="col">Acciones</th>{/if}
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
                    {#if canManage || canDelete}
                        <td>
                            {#if hasActions(row)}
                                <Dropdown
                                    placement="bottom-end"
                                    disabled={processingCode === row.code}
                                    aria-label={`Acciones de matrícula ${row.roll_code}`}
                                >
                                    {#snippet triggerContent()}
                                        <Button
                                            type="button"
                                            variant="flat"
                                            size="sm"
                                            icon="moreVertical"
                                            loading={processingCode === row.code}
                                            aria-label={`Abrir acciones de matrícula ${row.roll_code}`}
                                        />
                                    {/snippet}
                                    {#snippet content()}
                                        {#if canManage && row.status !== 'finalized'}
                                            <DropdownItem
                                                icon="edit"
                                                onclick={() =>
                                                    router.visit(`/enrollments/${row.code}/edit`)}
                                            >
                                                Editar matrícula
                                            </DropdownItem>
                                            <DropdownItem
                                                icon={row.is_active ? 'lock' : 'key'}
                                                color={row.is_active ? 'warning' : 'success'}
                                                onclick={() => updateState(row, !row.is_active)}
                                            >
                                                {row.is_active ? 'Desactivar' : 'Activar'}
                                            </DropdownItem>
                                        {/if}
                                        {#if canDelete}
                                            <DropdownItem
                                                icon="trash"
                                                color="danger"
                                                onclick={() => confirmDelete(row)}
                                            >
                                                Eliminar matrícula
                                            </DropdownItem>
                                        {/if}
                                    {/snippet}
                                </Dropdown>
                            {:else}
                                <span class="lumi-text--sm lumi-text--muted">Sólo lectura</span>
                            {/if}
                        </td>
                    {/if}
                {/snippet}
            </Table>
        {/if}
    </div>
</DashboardSection>

<Dialog
    open={deleteOpen}
    title="Eliminar matrícula"
    size="sm"
    persistent={processingCode !== null}
    onclose={closeDelete}
>
    <div class="lumi-stack lumi-stack--md">
        <p class="lumi-margin--none">
            Se eliminará definitivamente la matrícula
            <strong>{pendingDelete?.roll_code}</strong> del ciclo
            <strong>{pendingDelete?.cycle_name}</strong>. Esta acción no se puede deshacer.
        </p>
        <div class="lumi-flex lumi-justify--end lumi-flex--gap-sm">
            <Button
                type="button"
                variant="border"
                disabled={processingCode !== null}
                onclick={closeDelete}
            >
                Cancelar
            </Button>
            <Button
                type="button"
                color="danger"
                icon="trash"
                loading={processingCode !== null}
                onclick={remove}
            >
                Eliminar
            </Button>
        </div>
    </div>
</Dialog>
