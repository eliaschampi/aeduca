<script lang="ts">
    import { untrack } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import {
        Alert,
        Button,
        Card,
        Chip,
        Dialog,
        EmptyState,
        Input,
        PageHeader,
        PageSidebar,
        Table,
        Tooltip,
        UserInfo,
    } from '@lumi-ui/svelte';
    import type {
        StudentAttentionBranch,
        StudentAttentionCertificate,
        StudentAttentionSummary,
    } from '@/types/student-attention';

    interface Props {
        branch: StudentAttentionBranch;
        attentions: {
            data: StudentAttentionSummary[];
            current_page: number;
            last_page: number;
            total: number;
        };
        filters: { month: string };
        business_timezone: string;
        can_manage?: boolean;
    }

    const { branch, attentions, filters, business_timezone, can_manage = false }: Props = $props();

    let month = $state(untrack(() => filters.month));
    let filtersOpen = $state(false);
    let attentionToDelete = $state<StudentAttentionSummary | null>(null);
    let deleting = $state(false);
    let certificateCode = $state<string | null>(null);
    let certificateError = $state('');

    const dateFormatter = $derived(
        new Intl.DateTimeFormat('es-PE', {
            timeZone: business_timezone,
            dateStyle: 'medium',
            timeStyle: 'short',
        }),
    );
    const monthFormatter = new Intl.DateTimeFormat('es-PE', {
        timeZone: 'UTC',
        month: 'long',
        year: 'numeric',
    });
    const monthLabel = $derived(monthFormatter.format(new Date(`${filters.month}-01T00:00:00Z`)));

    function apply(page = 1): void {
        router.get(
            '/student-attentions',
            { month, page },
            { preserveScroll: true, preserveState: true, replace: true },
        );
        filtersOpen = false;
    }

    function formatDate(value: string): string {
        try {
            return dateFormatter.format(new Date(value));
        } catch {
            return value;
        }
    }

    async function generateCertificate(attention: StudentAttentionSummary): Promise<void> {
        if (certificateCode) return;

        const printWindow = window.open('', '_blank');
        if (!printWindow) {
            certificateError =
                'El navegador bloqueó la constancia. Permite las ventanas emergentes e inténtalo nuevamente.';
            return;
        }

        certificateCode = attention.code;
        certificateError = '';

        try {
            const response = await fetch(`/student-attentions/${attention.code}/certificate`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const payload = (await response.json()) as StudentAttentionCertificate & {
                message?: string;
            };

            if (!response.ok) {
                throw new Error(payload.message ?? 'No se pudo cargar la constancia.');
            }

            const { generateStudentAttentionCertificatePdf } =
                await import('@/lib/student-attention-pdf');
            await generateStudentAttentionCertificatePdf(payload, printWindow);
        } catch (caught) {
            printWindow.close();
            certificateError =
                caught instanceof Error ? caught.message : 'No se pudo generar la constancia.';
        } finally {
            certificateCode = null;
        }
    }

    function destroyAttention(): void {
        if (!attentionToDelete || deleting) return;

        deleting = true;
        router.delete(
            `/student-attentions/${attentionToDelete.code}?month=${encodeURIComponent(filters.month)}`,
            {
                preserveScroll: true,
                onSuccess: () => {
                    attentionToDelete = null;
                },
                onFinish: () => {
                    deleting = false;
                },
            },
        );
    }
</script>

<svelte:head>
    <title>Atenciones · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title="Atenciones"
        subtitle={`Registro de atenciones estudiantiles de ${branch.name}.`}
        icon="clipboardPenLine"
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
                    Mes
                </Button>
                {#if can_manage}
                    <Button
                        type="button"
                        icon="plus"
                        onclick={() =>
                            router.visit(
                                `/student-attentions/create?month=${encodeURIComponent(filters.month)}`,
                            )}
                    >
                        Nueva atención
                    </Button>
                {/if}
            </div>
        {/snippet}
    </PageHeader>

    {#if certificateError}
        <Alert color="danger" closable onclose={() => (certificateError = '')}>
            {certificateError}
        </Alert>
    {/if}

    <div class="lumi-layout--two-columns lumi-page-sidebar-layout">
        <PageSidebar
            bind:mobileOpen={filtersOpen}
            mobileTitle="Filtrar atenciones"
            mobileAriaLabel="Cerrar filtros"
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
                        <p class="lumi-filter-summary__eyebrow">Periodo de consulta</p>
                        <h2 class="lumi-filter-summary__title">{monthLabel}</h2>
                        <p class="lumi-filter-summary__subtitle">
                            Consulta las atenciones registradas durante un mes.
                        </p>
                    </div>
                    <Input bind:value={month} type="month" label="Mes" icon="calendar" />
                    <Button type="submit" icon="listChecks">Ver atenciones</Button>
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
                            <p class="lumi-filter-summary__eyebrow">Atenciones registradas</p>
                            <h2 class="lumi-filter-summary__title">{monthLabel}</h2>
                            <p class="lumi-filter-summary__subtitle">{branch.name}</p>
                        </div>
                        <div class="lumi-filter-summary__meta">
                            <Chip color="primary" size="sm">
                                {attentions.total}
                                {attentions.total === 1 ? 'atención' : 'atenciones'}
                            </Chip>
                        </div>
                    </div>

                    {#if attentions.data.length === 0}
                        <EmptyState
                            icon="clipboardPenLine"
                            title="Sin atenciones en este mes"
                            description="Selecciona otro mes o registra una nueva atención."
                        >
                            {#snippet actions()}
                                {#if can_manage}
                                    <Button
                                        type="button"
                                        icon="plus"
                                        onclick={() =>
                                            router.visit(
                                                `/student-attentions/create?month=${encodeURIComponent(filters.month)}`,
                                            )}
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
                            pagination={attentions.last_page > 1}
                            paginationMode="server"
                            currentPage={attentions.current_page}
                            totalItems={attentions.total}
                            itemsPerPage={20}
                            onpage-change={apply}
                            noDataText="No hay atenciones en este mes."
                            aria-label="Atenciones estudiantiles"
                        >
                            {#snippet thead()}
                                <th scope="col">Alumno</th>
                                <th scope="col">Fecha</th>
                                <th scope="col">Tipo</th>
                                <th scope="col">Motivo</th>
                                <th scope="col">Registrado por</th>
                                <th scope="col">Acciones</th>
                            {/snippet}

                            {#snippet row({ row }: { row: StudentAttentionSummary })}
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
                                <td>
                                    <div
                                        class="lumi-flex lumi-align-items--center lumi-flex--gap-xs"
                                    >
                                        <span class="lumi-font--medium">{row.reason}</span>
                                        {#if row.has_attachment}
                                            <Tooltip text="Tiene archivo adjunto">
                                                <Chip color="info" size="sm" icon="link"
                                                    >Adjunto</Chip
                                                >
                                            </Tooltip>
                                        {/if}
                                    </div>
                                </td>
                                <td>{row.author_name}</td>
                                <td>
                                    <div class="lumi-flex lumi-flex--wrap lumi-flex--gap-xs">
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="border"
                                            icon="fileText"
                                            loading={certificateCode === row.code}
                                            disabled={certificateCode !== null}
                                            onclick={() => void generateCertificate(row)}
                                        >
                                            Constancia
                                        </Button>
                                        {#if can_manage}
                                            <Tooltip text="Editar atención">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="flat"
                                                    icon="edit"
                                                    aria-label={`Editar atención de ${row.student_first_name} ${row.student_last_name}`}
                                                    onclick={() =>
                                                        router.visit(
                                                            `/student-attentions/${row.code}/edit?month=${encodeURIComponent(filters.month)}`,
                                                        )}
                                                />
                                            </Tooltip>
                                            <Tooltip text="Eliminar atención" color="danger">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="flat"
                                                    color="danger"
                                                    icon="trash"
                                                    aria-label={`Eliminar atención de ${row.student_first_name} ${row.student_last_name}`}
                                                    onclick={() => (attentionToDelete = row)}
                                                />
                                            </Tooltip>
                                        {/if}
                                    </div>
                                </td>
                            {/snippet}
                        </Table>
                    {/if}
                </div>
            </Card>
        </section>
    </div>
</div>

<Dialog
    open={attentionToDelete !== null}
    title="Eliminar atención"
    size="sm"
    persistent={deleting}
    onclose={() => {
        if (!deleting) attentionToDelete = null;
    }}
>
    <div class="lumi-stack lumi-stack--md">
        <p class="lumi-margin--none">
            Se eliminará la atención <strong>{attentionToDelete?.reason}</strong> de
            {attentionToDelete?.student_first_name}
            {attentionToDelete?.student_last_name}. El archivo adjunto permanecerá en Drive.
        </p>
        <div class="lumi-flex lumi-justify--end lumi-flex--gap-sm">
            <Button
                type="button"
                variant="border"
                disabled={deleting}
                onclick={() => (attentionToDelete = null)}
            >
                Cancelar
            </Button>
            <Button
                type="button"
                color="danger"
                icon="trash"
                loading={deleting}
                onclick={destroyAttention}
            >
                Eliminar
            </Button>
        </div>
    </div>
</Dialog>
