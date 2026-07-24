<script lang="ts">
    import { untrack } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import { Button, Chip, EmptyState, Input, PageHeader, Table, UserInfo } from '@lumi-ui/svelte';

    interface EnrollmentContext {
        roll_code: string;
        is_active: boolean;
        status: 'active' | 'inactive' | 'finalized';
        status_label: string;
        cycle_name: string;
        degree_label: string;
        group_name: string;
        branch_name: string;
    }

    interface StudentRow {
        code: string;
        dni: string;
        first_name: string;
        last_name: string;
        photo_url: string | null;
        is_active: boolean;
        enrollment: EnrollmentContext | null;
    }

    interface Props {
        students: {
            data: StudentRow[];
            current_page: number;
            last_page: number;
            total: number;
        };
        filters: { q: string };
        can_manage?: boolean;
        can_view_enrollments?: boolean;
    }

    const { students, filters, can_manage = false, can_view_enrollments = false }: Props = $props();

    let search = $state(untrack(() => filters.q));

    function visit(page = 1): void {
        router.get(
            '/students/search',
            { q: search.trim(), page },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    }

    function openStudent(student: StudentRow): void {
        router.visit(`/students/${student.code}`);
    }
</script>

<svelte:head>
    <title>Alumnos · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title="Alumnos"
        subtitle="Directorio institucional por DNI, nombre o código de matrícula activo."
        icon="graduationCap"
        size="xl"
    >
        {#snippet actions()}
            <div class="lumi-flex lumi-flex--wrap lumi-flex--gap-sm">
                {#if can_view_enrollments}
                    <Button
                        type="button"
                        variant="border"
                        icon="list"
                        onclick={() => router.visit('/students')}
                    >
                        Ver matrículas
                    </Button>
                {/if}
                {#if can_manage}
                    <Button
                        type="button"
                        icon="userPlus"
                        onclick={() => router.visit('/students/create')}
                    >
                        Nuevo alumno
                    </Button>
                {/if}
            </div>
        {/snippet}
    </PageHeader>

    <section class="lumi-search-panel">
        <div class="lumi-search-panel__copy">
            <h2 class="lumi-search-panel__title">Directorio institucional</h2>
            <p class="lumi-search-panel__subtitle">
                Encuentra una identidad aunque todavía no tenga matrícula activa.
            </p>
        </div>
        <form
            class="lumi-search-panel__form"
            onsubmit={(event) => {
                event.preventDefault();
                visit();
            }}
        >
            <div class="lumi-inline-filters lumi-inline-filters--compact">
                <div class="lumi-flex-item--grow lumi-min-width--0">
                    <Input
                        bind:value={search}
                        name="q"
                        label="Buscar alumno"
                        placeholder="Ej. 76543210, Valeria Ramos o 0042"
                        icon="search"
                    />
                </div>
                <div class="lumi-inline-filters__actions">
                    <Button type="submit" icon="search">Buscar</Button>
                    {#if filters.q}
                        <Button
                            type="button"
                            variant="border"
                            icon="x"
                            onclick={() => {
                                search = '';
                                visit();
                            }}
                        >
                            Limpiar
                        </Button>
                    {/if}
                </div>
            </div>
        </form>
    </section>

    {#if students.data.length === 0}
        <EmptyState
            icon="search"
            title={filters.q ? 'Sin coincidencias' : 'Sin alumnos registrados'}
            description={filters.q
                ? 'Prueba con el DNI completo, otro nombre o un código activo.'
                : 'Registra el primer alumno para iniciar el directorio institucional.'}
        >
            {#snippet actions()}
                {#if can_manage}
                    <Button
                        type="button"
                        icon="userPlus"
                        onclick={() => router.visit('/students/create')}
                    >
                        Registrar alumno
                    </Button>
                {/if}
            {/snippet}
        </EmptyState>
    {:else}
        <Table
            data={students.data}
            rowKey={(student) => student.code}
            hover
            pagination={students.last_page > 1}
            paginationMode="server"
            currentPage={students.current_page}
            totalItems={students.total}
            itemsPerPage={15}
            onpage-change={visit}
            onrow-click={openStudent}
            noDataText="No hay alumnos."
            aria-label="Directorio de alumnos"
        >
            {#snippet thead()}
                <th scope="col">Alumno</th>
                <th scope="col">DNI</th>
                <th scope="col">Matrícula reciente</th>
                <th scope="col">Estado</th>
            {/snippet}

            {#snippet row({ row }: { row: StudentRow })}
                <td>
                    <UserInfo
                        name={row.first_name}
                        lastName={row.last_name}
                        description={row.enrollment?.branch_name ?? 'Sin matrícula'}
                        photoUrl={row.photo_url ?? undefined}
                        avatarSize="sm"
                        avatarColor="primary"
                    />
                </td>
                <td>{row.dni}</td>
                <td>
                    {#if row.enrollment}
                        <div class="lumi-stack lumi-stack--2xs">
                            <span class="lumi-font--medium">
                                {row.enrollment.cycle_name} · {row.enrollment.degree_label} ·
                                {row.enrollment.group_name}
                            </span>
                            <span class="lumi-text--xs lumi-text--muted">
                                Código {row.enrollment.roll_code}
                            </span>
                        </div>
                    {:else}
                        <span class="lumi-text--muted">Sin matrícula</span>
                    {/if}
                </td>
                <td>
                    <div class="lumi-flex lumi-flex--wrap lumi-flex--gap-xs">
                        <Chip color={row.is_active ? 'success' : 'secondary'} size="sm">
                            {row.is_active ? 'Activo' : 'Inactivo'}
                        </Chip>
                        {#if row.enrollment}
                            <Chip
                                color={row.enrollment.status === 'active' ? 'info' : 'secondary'}
                                size="sm"
                            >
                                {row.enrollment.status === 'active'
                                    ? 'Matriculado'
                                    : row.enrollment.status_label}
                            </Chip>
                        {/if}
                    </div>
                </td>
            {/snippet}
        </Table>
    {/if}
</div>
