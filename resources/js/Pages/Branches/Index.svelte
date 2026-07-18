<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import {
        Alert,
        Button,
        Card,
        Chip,
        Dialog,
        EmptyState,
        InfoItem,
        Input,
        PageHeader,
        Switch,
        Title,
    } from '@lumi-ui/svelte';

    interface CatalogBranch {
        code: string;
        name: string;
        is_active: boolean;
        employees_count: number;
    }

    interface Props {
        catalog?: CatalogBranch[];
        can_view_catalog?: boolean;
        can_manage?: boolean;
    }

    const {
        catalog = [],
        can_view_catalog = false,
        can_manage = false,
    }: Props = $props();

    const auth = $derived(page.props.auth);
    const workspaceCodes = $derived(new Set(auth?.branches.map((b) => b.code) ?? []));

    let switchingBranchCode = $state<string | null>(null);
    let branchFilter = $state('');
    let dialogOpen = $state(false);
    let editing = $state<CatalogBranch | null>(null);
    let formName = $state('');
    let formActive = $state(true);
    let processing = $state(false);
    let formError = $state<string | null>(null);
    let nameError = $state<string | null>(null);

    const normalizedFilter = $derived(branchFilter.trim().toLowerCase());

    const cards = $derived.by(() => {
        if (can_view_catalog) {
            const source =
                normalizedFilter.length === 0
                    ? catalog
                    : catalog.filter((branch) =>
                          branch.name.toLowerCase().includes(normalizedFilter),
                      );

            return source.map((branch) => ({
                code: branch.code,
                name: branch.name,
                is_active: branch.is_active as boolean | null,
                employees_count: branch.employees_count,
                canUse: workspaceCodes.has(branch.code),
                editable: can_manage,
                source: branch as CatalogBranch | null,
            }));
        }

        return (auth?.branches ?? []).map((branch) => ({
            code: branch.code,
            name: branch.name,
            is_active: null as boolean | null,
            employees_count: null as number | null,
            canUse: true,
            editable: false,
            source: null as CatalogBranch | null,
        }));
    });

    function openCreate(): void {
        if (!can_manage) return;
        editing = null;
        formName = '';
        formActive = true;
        formError = null;
        nameError = null;
        dialogOpen = true;
    }

    function openEdit(branch: CatalogBranch): void {
        if (!can_manage) return;
        editing = branch;
        formName = branch.name;
        formActive = branch.is_active;
        formError = null;
        nameError = null;
        dialogOpen = true;
    }

    function closeDialog(): void {
        dialogOpen = false;
    }

    function selectBranch(branchCode: string): void {
        switchingBranchCode = branchCode;
        router.put(
            '/current-branch',
            { branch_code: branchCode },
            {
                preserveScroll: true,
                onFinish: () => {
                    switchingBranchCode = null;
                },
            },
        );
    }

    function submit(): void {
        if (processing) return;

        const payload = {
            name: formName,
            is_active: formActive,
        };

        const options = {
            preserveScroll: true,
            onStart: () => {
                processing = true;
                formError = null;
                nameError = null;
            },
            onError: (errors: Record<string, string>) => {
                nameError = errors.name ?? null;
                formError =
                    errors.message ??
                    (nameError ? null : 'No se pudo guardar la sede.');
            },
            onSuccess: () => {
                dialogOpen = false;
            },
            onFinish: () => {
                processing = false;
            },
        };

        if (editing) {
            router.put(`/admin/branches/${editing.code}`, payload, options);
        } else {
            router.post('/admin/branches', payload, options);
        }
    }
</script>

<svelte:head>
    <title>Sedes · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title="Sedes"
        subtitle={can_view_catalog
            ? 'Elige tu sede de trabajo y administra el catálogo cuando tengas permiso.'
            : 'Elige la sede con la que trabajarás durante esta sesión.'}
        icon="building2"
        size="xl"
    >
        {#snippet actions()}
            {#if can_manage}
                <Button type="button" icon="plus" onclick={openCreate}>
                    Nueva sede
                </Button>
            {/if}
        {/snippet}
    </PageHeader>

    {#if can_view_catalog && catalog.length > 0}
        <div class="lumi-flex lumi-flex--gap-md lumi-align-items--center lumi-flex--wrap">
            <div class="lumi-flex-item--grow">
                <Input
                    bind:value={branchFilter}
                    icon="search"
                    placeholder="Buscar por nombre de sede…"
                    aria-label="Filtrar sedes por nombre"
                />
            </div>
            <span class="lumi-text--sm lumi-text--muted">
                {cards.length} de {catalog.length}
            </span>
        </div>
    {/if}

    {#if cards.length === 0}
        {#if can_view_catalog && catalog.length === 0}
            <EmptyState
                icon="building2"
                title="Sin sedes registradas"
                description="Crea la primera sede. Los usuarios se asignan desde su ficha."
            >
                {#snippet actions()}
                    {#if can_manage}
                        <Button type="button" icon="plus" onclick={openCreate}>
                            Crear sede
                        </Button>
                    {/if}
                {/snippet}
            </EmptyState>
        {:else if can_view_catalog}
            <Alert color="info">No hay sedes que coincidan con tu búsqueda.</Alert>
        {:else}
            <EmptyState
                icon="building2"
                title="Sin sedes disponibles"
                description="No tienes sedes asignadas. Solicita acceso a un administrador."
            />
        {/if}
    {:else}
        <div
            class="lumi-grid lumi-grid--cards lumi-grid--gap-md"
            role="list"
            aria-label={can_view_catalog ? 'Catálogo de sedes' : 'Sedes de trabajo'}
        >
            {#each cards as branch (branch.code)}
                {@const isCurrent = auth?.current_branch?.code === branch.code}
                <div role="listitem">
                    <Card
                        spaced
                        hoverable
                        selected={isCurrent}
                        class="lumi-width--full lumi-h--full"
                    >
                        <div class="lumi-stack lumi-stack--md">
                            <div
                                class="lumi-flex lumi-justify--between lumi-align-items--start lumi-flex--gap-md"
                            >
                                <Title size="sm" icon="building2" title={branch.name} />
                                {#if branch.is_active !== null}
                                    <Chip
                                        color={branch.is_active ? 'success' : 'secondary'}
                                        size="sm"
                                    >
                                        {branch.is_active ? 'Habilitada' : 'Deshabilitada'}
                                    </Chip>
                                {/if}
                            </div>

                            {#if branch.employees_count !== null}
                                <InfoItem
                                    icon="users"
                                    iconColor="info"
                                    label="Usuarios asignados"
                                    value={`${branch.employees_count}`}
                                />
                            {/if}

                            <div
                                class="lumi-flex lumi-justify--end lumi-align-items--center lumi-flex--gap-xs"
                            >
                                {#if isCurrent}
                                    <Button
                                        type="button"
                                        size="sm"
                                        icon="checkCircle"
                                        disabled
                                    >
                                        Sede activa
                                    </Button>
                                {:else}
                                    <Button
                                        type="button"
                                        variant="border"
                                        size="sm"
                                        icon="mapPin"
                                        loading={switchingBranchCode === branch.code}
                                        disabled={!branch.canUse || switchingBranchCode !== null}
                                        onclick={() => selectBranch(branch.code)}
                                    >
                                        Usar sede
                                    </Button>
                                {/if}

                                {#if branch.editable && branch.source}
                                    <Button
                                        type="button"
                                        variant="flat"
                                        size="sm"
                                        icon="edit"
                                        color="info"
                                        aria-label={`Editar ${branch.name}`}
                                        onclick={() => branch.source && openEdit(branch.source)}
                                    />
                                {/if}
                            </div>
                        </div>
                    </Card>
                </div>
            {/each}
        </div>
    {/if}
</div>

{#if can_manage}
    <Dialog
        open={dialogOpen}
        title={editing ? 'Editar sede' : 'Nueva sede'}
        size="md"
        onclose={closeDialog}
    >
        <form
            class="lumi-stack lumi-stack--md"
            onsubmit={(event) => {
                event.preventDefault();
                submit();
            }}
        >
            {#if formError}
                <Alert color="danger">{formError}</Alert>
            {/if}

            <Input
                label="Nombre de la sede"
                placeholder="Ej. Sede principal"
                bind:value={formName}
                maxlength={120}
                required
                danger={nameError !== null}
                dangerText={nameError ?? undefined}
            />

            <Switch bind:checked={formActive} label="Sede habilitada" />

            <p class="lumi-text--sm lumi-text--muted lumi-margin--none">
                Los usuarios se asignan a sedes desde su ficha de personal, no aquí.
            </p>

            <div class="lumi-flex lumi-justify--end lumi-flex--gap-sm">
                <Button type="button" variant="border" onclick={closeDialog}>
                    Cancelar
                </Button>
                <Button type="submit" icon="check" loading={processing}>
                    Guardar
                </Button>
            </div>
        </form>
    </Dialog>
{/if}
