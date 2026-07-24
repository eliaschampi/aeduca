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
        UserInfo,
        type SelectOption,
    } from '@lumi-ui/svelte';

    interface RosterRow {
        code: string;
        student_code: string;
        dni: string;
        first_name: string;
        last_name: string;
        photo_url: string | null;
        student_is_active: boolean;
        roll_code: string;
        cycle_name: string;
        degree_label: string;
        group_name: string;
        shift_names: string;
    }

    interface CatalogGroup {
        code: string;
        name: string;
    }

    interface CatalogDegree {
        number: number;
        label: string;
        groups: CatalogGroup[];
    }

    interface CatalogCycle {
        code: string;
        name: string;
        degrees: CatalogDegree[];
    }

    interface Props {
        enrollments: {
            data: RosterRow[];
            current_page: number;
            last_page: number;
            total: number;
        };
        filters: {
            q: string;
            cycle: string;
            degree: string;
            group: string;
        };
        context_complete?: boolean;
        catalog: CatalogCycle[];
        can_manage?: boolean;
        can_view_profiles?: boolean;
    }

    const {
        enrollments,
        filters,
        context_complete = false,
        catalog,
        can_manage = false,
        can_view_profiles = false,
    }: Props = $props();

    function seedFilters() {
        return { ...filters };
    }

    let form = $state(untrack(seedFilters));
    let filtersOpen = $state(false);

    const selectedCycle = $derived(catalog.find((cycle) => cycle.code === form.cycle) ?? null);
    const selectedDegree = $derived(
        selectedCycle?.degrees.find((degree) => String(degree.number) === form.degree) ?? null,
    );
    const selectedGroup = $derived(
        selectedDegree?.groups.find((group) => group.code === form.group) ?? null,
    );
    const appliedCycle = $derived(catalog.find((cycle) => cycle.code === filters.cycle) ?? null);
    const appliedDegree = $derived(
        appliedCycle?.degrees.find((degree) => String(degree.number) === filters.degree) ?? null,
    );
    const appliedGroup = $derived(
        appliedDegree?.groups.find((group) => group.code === filters.group) ?? null,
    );
    const selectionComplete = $derived(Boolean(selectedCycle && selectedDegree && selectedGroup));
    const contextTitle = $derived(
        selectionComplete
            ? `${selectedDegree?.label} · Sección ${selectedGroup?.name}`
            : 'Selecciona una sección',
    );
    const appliedContextTitle = $derived(
        context_complete && appliedDegree && appliedGroup
            ? `${appliedDegree.label} · Sección ${appliedGroup.name}`
            : 'Selecciona una sección',
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

    function apply(page = 1): void {
        if (!selectionComplete) return;

        router.get(
            '/students',
            {
                q: form.q.trim(),
                cycle: form.cycle,
                degree: form.degree,
                group: form.group,
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
    }

    function changeDegree(value: unknown): void {
        form.degree = typeof value === 'string' ? value : '';
        form.group = '';
    }

    function openProfile(row: RosterRow): void {
        if (!can_view_profiles) return;
        router.visit(`/students/${row.student_code}`);
    }

    function editEnrollment(event: MouseEvent, row: RosterRow): void {
        event.stopPropagation();
        router.visit(`/enrollments/${row.code}/edit`);
    }

    function viewProfile(event: MouseEvent, row: RosterRow): void {
        event.stopPropagation();
        openProfile(row);
    }
</script>

<svelte:head>
    <title>Matriculados · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title="Matriculados"
        subtitle="Padrón activo de la sede actual, organizado por ciclo, grado y sección."
        icon="listChecks"
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
                <Button
                    type="button"
                    variant="border"
                    icon="search"
                    onclick={() => router.visit('/students/search')}
                >
                    Directorio
                </Button>
            </div>
        {/snippet}
    </PageHeader>

    <div class="lumi-layout--two-columns lumi-page-sidebar-layout">
        <PageSidebar
            bind:mobileOpen={filtersOpen}
            mobileTitle="Seleccionar sección"
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
                        <p class="lumi-filter-summary__eyebrow">Padrón activo</p>
                        <h2 class="lumi-filter-summary__title">{contextTitle}</h2>
                        <p class="lumi-filter-summary__subtitle">
                            {selectedCycle?.name ??
                                'Elige el contexto académico para cargar alumnos.'}
                        </p>
                    </div>

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
                    <Input
                        bind:value={form.q}
                        label="Buscar en la sección"
                        placeholder="Nombre, DNI o código"
                        icon="search"
                        disabled={!selectionComplete}
                    />
                    <Button type="submit" icon="listChecks" disabled={!selectionComplete}>
                        Ver matriculados
                    </Button>
                </form>
            {/snippet}
        </PageSidebar>

        <section class="lumi-layout--content-right lumi-min-width--0">
            <Card spaced>
                <div class="lumi-stack lumi-stack--md">
                    <div class="lumi-filter-summary lumi-filter-summary--secondary">
                        <div class="lumi-filter-summary__copy">
                            <p class="lumi-filter-summary__eyebrow">Matrículas activas</p>
                            <h2 class="lumi-filter-summary__title">{appliedContextTitle}</h2>
                            <p class="lumi-filter-summary__subtitle">
                                {appliedCycle?.name ??
                                    'Completa la selección para consultar un padrón acotado.'}
                            </p>
                        </div>
                        {#if context_complete}
                            <div class="lumi-filter-summary__meta">
                                <Chip color="primary" size="sm">
                                    {enrollments.total} matriculados
                                </Chip>
                                {#if filters.q}
                                    <Chip color="secondary" size="sm" icon="search">
                                        {filters.q}
                                    </Chip>
                                {/if}
                            </div>
                        {/if}
                    </div>

                    {#if !context_complete}
                        <EmptyState
                            icon="slidersHorizontal"
                            title="Selecciona ciclo, grado y sección"
                            description="El padrón se carga sólo para el grupo elegido y evita consultas institucionales innecesarias."
                        />
                    {:else if enrollments.data.length === 0}
                        <EmptyState
                            icon="users"
                            title="Sin alumnos matriculados"
                            description={filters.q
                                ? 'No hay coincidencias activas dentro de esta sección.'
                                : 'Esta sección no tiene matrículas activas.'}
                        />
                    {:else}
                        <Table
                            data={enrollments.data}
                            rowKey={(enrollment) => enrollment.code}
                            hover={can_view_profiles}
                            pagination={enrollments.last_page > 1}
                            paginationMode="server"
                            currentPage={enrollments.current_page}
                            totalItems={enrollments.total}
                            itemsPerPage={20}
                            onpage-change={apply}
                            onrow-click={openProfile}
                            noDataText="No hay matrículas activas."
                            aria-label="Alumnos matriculados activos"
                        >
                            {#snippet thead()}
                                <th scope="col">Alumno</th>
                                <th scope="col">Código</th>
                                <th scope="col">Turnos</th>
                                {#if can_manage || can_view_profiles}
                                    <th scope="col">Acciones</th>
                                {/if}
                            {/snippet}

                            {#snippet row({ row }: { row: RosterRow })}
                                <td>
                                    <div class="lumi-stack lumi-stack--2xs">
                                        <UserInfo
                                            name={row.first_name}
                                            lastName={row.last_name}
                                            description={`DNI ${row.dni}`}
                                            photoUrl={row.photo_url ?? undefined}
                                            avatarSize="sm"
                                            avatarColor="primary"
                                        />
                                        {#if !row.student_is_active}
                                            <Chip color="warning" size="sm">Identidad inactiva</Chip
                                            >
                                        {/if}
                                    </div>
                                </td>
                                <td><strong>{row.roll_code}</strong></td>
                                <td>{row.shift_names || '—'}</td>
                                {#if can_manage || can_view_profiles}
                                    <td>
                                        <Dropdown
                                            placement="bottom-end"
                                            aria-label={`Acciones de ${row.first_name} ${row.last_name}`}
                                        >
                                            {#snippet triggerContent()}
                                                <Button
                                                    type="button"
                                                    variant="flat"
                                                    size="sm"
                                                    icon="moreVertical"
                                                    aria-label={`Abrir acciones de ${row.first_name} ${row.last_name}`}
                                                    onclick={(event) => event.stopPropagation()}
                                                />
                                            {/snippet}
                                            {#snippet content()}
                                                {#if can_view_profiles}
                                                    <DropdownItem
                                                        icon="user"
                                                        onclick={(event) => viewProfile(event, row)}
                                                    >
                                                        Ver perfil
                                                    </DropdownItem>
                                                {/if}
                                                {#if can_manage}
                                                    <DropdownItem
                                                        icon="edit"
                                                        onclick={(event) =>
                                                            editEnrollment(event, row)}
                                                    >
                                                        Editar matrícula
                                                    </DropdownItem>
                                                {/if}
                                            {/snippet}
                                        </Dropdown>
                                    </td>
                                {/if}
                            {/snippet}
                        </Table>
                    {/if}
                </div>
            </Card>
        </section>
    </div>
</div>
