<script lang="ts">
    import { untrack } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import {
        Button,
        DashboardSection,
        EmptyState,
        Input,
        PageHeader,
        Table,
        UserInfo,
    } from '@lumi-ui/svelte';
    import { can } from '@/lib/permissions';

    interface StudentRow {
        code: string;
        dni: string;
        first_name: string;
        last_name: string;
        phone: string | null;
        address: string | null;
        created_at: string;
    }

    interface StudentPaginator {
        data: StudentRow[];
        current_page: number;
        per_page: number;
        total: number;
    }

    interface Props {
        students: StudentPaginator;
        q: string;
        can_manage: boolean;
    }

    const { students, q, can_manage }: Props = $props();

    const canManage = $derived(can_manage && can('students.manage'));
    let search = $state(untrack(() => q));

    $effect(() => {
        search = q;
    });

    const dateFormatter = new Intl.DateTimeFormat('es-PE', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });

    function openProfile(student: StudentRow): void {
        router.visit(`/students/${student.code}`);
    }

    function submitSearch(): void {
        visitDirectory(1, search.trim());
    }

    function clearSearch(): void {
        search = '';
        visitDirectory(1, '');
    }

    function visitDirectory(page: number, query: string = q): void {
        router.get(
            '/students',
            {
                ...(query ? { q: query } : {}),
                ...(page > 1 ? { page } : {}),
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }

    function formatDate(value: string): string {
        return dateFormatter.format(new Date(value));
    }
</script>

<svelte:head>
    <title>Estudiantes · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title="Estudiantes"
        subtitle="Identidad institucional y datos de contacto."
        icon="graduationCap"
        size="xl"
    >
        {#snippet actions()}
            {#if canManage}
                <Button
                    type="button"
                    icon="userPlus"
                    onclick={() => router.visit('/students/create')}
                >
                    Nuevo estudiante
                </Button>
            {/if}
        {/snippet}
    </PageHeader>

    <DashboardSection
        title="Directorio"
        subtitle={q
            ? `${students.total} resultado${students.total === 1 ? '' : 's'} para “${q}”.`
            : 'Los registros más recientes aparecen primero.'}
        icon="users"
    >
        {#snippet actions()}
            <form
                class="lumi-form-action-row lumi-form-action-row--toolbar"
                onsubmit={(event) => {
                    event.preventDefault();
                    submitSearch();
                }}
            >
                <div class="lumi-form-action-row__field">
                    <Input
                        type="search"
                        aria-label="Buscar estudiantes"
                        placeholder="DNI, nombres o apellidos"
                        icon="search"
                        bind:value={search}
                    />
                </div>
                <div class="lumi-form-action-row__actions">
                    {#if q}
                        <Button
                            type="button"
                            variant="flat"
                            icon="x"
                            aria-label="Limpiar búsqueda"
                            onclick={clearSearch}
                        />
                    {/if}
                    <Button type="submit" variant="border" icon="search">Buscar</Button>
                </div>
            </form>
        {/snippet}

        {#if students.data.length === 0}
            {#if q}
                <EmptyState
                    icon="search"
                    title="Sin coincidencias"
                    description="Prueba con otro DNI, nombre o apellido."
                >
                    {#snippet actions()}
                        <Button type="button" variant="border" onclick={clearSearch}>
                            Ver estudiantes recientes
                        </Button>
                    {/snippet}
                </EmptyState>
            {:else}
                <EmptyState
                    icon="graduationCap"
                    title="Aún no hay estudiantes"
                    description="Crea la primera ficha para iniciar el directorio institucional."
                >
                    {#snippet actions()}
                        {#if canManage}
                            <Button
                                type="button"
                                icon="userPlus"
                                onclick={() => router.visit('/students/create')}
                            >
                                Crear estudiante
                            </Button>
                        {/if}
                    {/snippet}
                </EmptyState>
            {/if}
        {:else}
            <Table
                data={students.data}
                rowKey={(student) => student.code}
                hover
                pagination
                paginationMode="server"
                currentPage={students.current_page}
                itemsPerPage={students.per_page}
                totalItems={students.total}
                onpage-change={(page) => visitDirectory(page)}
                onrow-click={openProfile}
                showingLabel="Mostrando"
                ofLabel="de"
                noResultsText="Sin resultados"
                aria-label="Directorio de estudiantes"
            >
                {#snippet thead()}
                    <th scope="col">DNI</th>
                    <th scope="col" class="lumi-min-w--xl">Estudiante</th>
                    <th scope="col">Teléfono</th>
                    <th scope="col">Dirección</th>
                    <th scope="col">Creación</th>
                {/snippet}

                {#snippet row({ row }: { row: StudentRow })}
                    <td>
                        <span class="lumi-font--medium">{row.dni}</span>
                    </td>
                    <td class="lumi-min-w--xl">
                        <UserInfo
                            name={row.first_name}
                            lastName={row.last_name}
                            avatarSize="sm"
                            avatarColor="primary"
                        />
                    </td>
                    <td>{row.phone ?? '—'}</td>
                    <td>
                        <span
                            class="lumi-text--sm lumi-text--muted"
                            title={row.address ?? undefined}
                        >
                            {row.address ?? '—'}
                        </span>
                    </td>
                    <td>
                        <span class="lumi-text--sm lumi-text--muted">
                            {formatDate(row.created_at)}
                        </span>
                    </td>
                {/snippet}
            </Table>
        {/if}
    </DashboardSection>
</div>
