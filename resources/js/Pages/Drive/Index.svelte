<script lang="ts">
    import { onDestroy, onMount } from 'svelte';
    import {
        Alert,
        Button,
        Card,
        Chip,
        Context,
        ContextItem,
        Dialog,
        DriveFileGrid,
        DriveFileList,
        DriveFilePreview,
        DriveFileUploader,
        DriveSidebar,
        EmptyState,
        Input,
        List,
        ListItem,
        Loading,
        PageHeader,
        PageSidebar,
        SegmentedControl,
        Select,
        Table,
    } from '@lumi-ui/svelte';
    import type { IconName } from '@lumi-ui/svelte';
    import {
        DRIVE_MENU_OPTIONS,
        formatDriveDate,
        formatFileSize,
        getDriveServeUrl,
        type DriveMenuOption,
        type DriveServeUrlOptions,
    } from '@lumi-ui/svelte/drive';
    import type {
        DriveCrumb,
        DriveFolderOption,
        DriveItem,
        DriveRecipient,
        DriveShareRow,
        DriveStorageInfo,
        DriveView,
    } from '@/types/drive';

    interface Props {
        storage: DriveStorageInfo;
        recipients: DriveRecipient[];
    }

    const { storage, recipients }: Props = $props();

    const SHARE_VIEWS: { view: DriveView; name: string; subtitle: string; icon: IconName }[] = [
        {
            view: 'shared_by_me',
            name: 'Compartidos por mí',
            subtitle: 'Archivos que diste a otros',
            icon: 'send',
        },
        {
            view: 'shared_with_me',
            name: 'Compartidos conmigo',
            subtitle: 'Archivos que te dieron',
            icon: 'inbox',
        },
    ];

    let files = $state<DriveItem[]>([]);
    let path = $state<DriveCrumb[]>([]);
    let owned = $state(true);
    let loading = $state(false);
    let busy = $state(false);
    let feedback = $state<{ color: 'danger' | 'success'; message: string } | null>(null);

    let view = $state<DriveView>('folder');
    let currentParent = $state<string | null>(null);
    let searchQuery = $state('');
    let viewMode = $state<'grid' | 'list'>('grid');
    let storageInfo = $state<DriveStorageInfo>({ ...storage });
    let showMobileSidebar = $state(false);

    let showUploader = $state(false);
    let showPreview = $state(false);
    let showCreateFolder = $state(false);
    let showRename = $state(false);
    let showMove = $state(false);
    let showShare = $state(false);

    let previewFile = $state<DriveItem | null>(null);
    let contextFile = $state<DriveItem | null>(null);

    let folderName = $state('');
    let renameName = $state('');
    let folderOptions = $state<DriveFolderOption[]>([]);
    let moveTarget = $state<string>('root');

    let shareFile = $state<DriveItem | null>(null);
    let shareRows = $state<DriveShareRow[]>([]);
    let shareRecipient = $state<string | null>(null);
    let shareBusy = $state(false);

    let confirmation = $state<{ title: string; message: string; run: () => Promise<void> } | null>(
        null,
    );

    let searchTimer: ReturnType<typeof setTimeout> | undefined;
    let requestId = 0;

    let fileMenu:
        { open: (event: MouseEvent, data?: unknown) => void; close: () => void } | undefined =
        $state();

    const isTrashView = $derived(view === 'trash');
    const isFolderView = $derived(view === 'folder' && searchQuery.trim() === '');
    /** Received folders are browsable, so writing depends on the folder, not the view. */
    const canWriteHere = $derived(isFolderView && owned);

    /** Lumi's sidebar highlights "Mi unidad" whenever no menu is selected. */
    const sidebarMenu = $derived.by<DriveMenuOption | null>(() => {
        const shareView = SHARE_VIEWS.find((entry) => entry.view === view);
        if (shareView) {
            return { name: shareView.name, value: shareView.view, icon: shareView.icon };
        }

        return (
            [...DRIVE_MENU_OPTIONS.main, ...DRIVE_MENU_OPTIONS.trash].find(
                (menu) => menu.value === view,
            ) ?? null
        );
    });

    /** Inside a received folder the trail returns to the share list, not to my root. */
    const breadcrumbs = $derived<DriveCrumb[]>([
        { code: '', name: owned ? 'Mi unidad' : 'Compartidos conmigo' },
        ...path,
    ]);

    const pageTitle = $derived(
        searchQuery.trim()
            ? 'Búsqueda'
            : (path[path.length - 1]?.name ?? sidebarMenu?.name ?? 'Mi unidad'),
    );

    const availableRecipients = $derived(
        recipients.filter(
            (recipient) => !shareRows.some((row) => row.user_code === recipient.code),
        ),
    );

    const moveOptions = $derived([
        { value: 'root', label: 'Raíz (Mi unidad)' },
        ...folderOptions.map((folder) => ({ value: folder.code, label: folder.label })),
    ]);

    const emptyStateCopy = $derived.by(() => {
        if (isTrashView) {
            return {
                title: 'La papelera está vacía',
                description: 'Lo que elimines aparecerá aquí.',
            };
        }

        if (searchQuery.trim()) {
            return { title: 'Sin resultados', description: 'Prueba con otro término.' };
        }

        if (view === 'shared_by_me') {
            return {
                title: 'No has compartido archivos',
                description: 'Comparte un archivo desde su menú contextual.',
            };
        }

        if (view === 'shared_with_me') {
            return {
                title: 'Nadie te ha compartido archivos',
                description: 'Aquí verás lo que otros usuarios compartan contigo.',
            };
        }

        return { title: 'Carpeta vacía', description: 'Sube archivos o crea una carpeta.' };
    });

    function serveUrl(fileCode: string, options: DriveServeUrlOptions = {}): string {
        return getDriveServeUrl(fileCode, { ...options, basePath: '/drive/files' });
    }

    async function request<T>(url: string, init: RequestInit = {}): Promise<T> {
        const csrf =
            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

        const response = await fetch(url, {
            credentials: 'same-origin',
            ...init,
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
                ...init.headers,
            },
        });

        const payload = await response.json().catch(() => null);

        if (!response.ok) {
            throw new Error(
                (payload as { message?: string } | null)?.message ??
                    'No se pudo completar la operación.',
            );
        }

        return payload as T;
    }

    /**
     * Runs a mutation and reports it. Operations whose effect on the list is
     * known — a row leaving it — apply that locally instead of paying a second
     * round trip for a result we already have.
     */
    async function mutate(
        message: string,
        operation: () => Promise<void>,
        applyLocally?: () => void,
    ): Promise<boolean> {
        busy = true;

        try {
            await operation();

            if (applyLocally) {
                applyLocally();
            } else {
                await loadFiles();
            }

            feedback = { color: 'success', message };

            return true;
        } catch (caught) {
            feedback = {
                color: 'danger',
                message: caught instanceof Error ? caught.message : 'Ocurrió un error.',
            };

            return false;
        } finally {
            busy = false;
        }
    }

    function dropRow(code: string): void {
        files = files.filter((item) => item.code !== code);
    }

    async function loadFiles(): Promise<void> {
        const current = ++requestId;
        loading = true;

        try {
            const params = new URLSearchParams({ view });
            const term = searchQuery.trim();

            if (view === 'folder') {
                if (term) {
                    params.set('search', term);
                } else if (currentParent) {
                    params.set('parent', currentParent);
                }
            }

            const payload = await request<{
                files: DriveItem[];
                path: DriveCrumb[];
                owned: boolean;
            }>(`/drive/files?${params.toString()}`);

            if (current !== requestId) {
                return;
            }

            files = payload.files;
            path = payload.path;
            owned = payload.owned;
        } catch (caught) {
            if (current === requestId) {
                feedback = {
                    color: 'danger',
                    message:
                        caught instanceof Error
                            ? caught.message
                            : 'No se pudieron cargar los archivos.',
                };
                files = [];
                path = [];
                owned = true;
            }
        } finally {
            if (current === requestId) {
                loading = false;
            }
        }
    }

    async function loadFolderOptions(excludeCode: string | null): Promise<void> {
        const params = new URLSearchParams();
        if (excludeCode) {
            params.set('exclude', excludeCode);
        }

        const payload = await request<{ folders: DriveFolderOption[] }>(
            `/drive/folders?${params.toString()}`,
        );
        folderOptions = payload.folders;
    }

    function selectView(next: DriveView): void {
        view = next;
        currentParent = null;
        searchQuery = '';
        path = [];
        void loadFiles();
    }

    function openFolder(file: DriveItem): void {
        view = 'folder';
        searchQuery = '';
        currentParent = file.code;
        void loadFiles();
    }

    function navigateCrumb(crumb: DriveCrumb): void {
        if (crumb.code === '' && !owned) {
            selectView('shared_with_me');

            return;
        }

        currentParent = crumb.code === '' ? null : crumb.code;
        void loadFiles();
    }

    function handleSearchInput(value: string): void {
        searchQuery = value;
        view = 'folder';

        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => void loadFiles(), 350);
    }

    async function createFolder(): Promise<void> {
        const created = await mutate('Carpeta creada', async () => {
            await request('/drive/folders', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: folderName, parent_code: currentParent }),
            });
        });

        if (created) {
            showCreateFolder = false;
            folderName = '';
        }
    }

    async function uploadFile(file: File, customName?: string): Promise<void> {
        const body = new FormData();
        body.append('file', file);
        if (customName) {
            body.append('name', customName);
        }
        if (currentParent) {
            body.append('parent_code', currentParent);
        }

        const payload = await request<{ storage: DriveStorageInfo }>('/drive/files', {
            method: 'POST',
            body,
        });
        storageInfo = payload.storage;
    }

    async function patchFile(file: DriveItem, body: Record<string, unknown>): Promise<void> {
        await request(`/drive/files/${file.code}`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
    }

    async function renameFile(): Promise<void> {
        if (!contextFile) {
            return;
        }

        const file = contextFile;
        const renamed = await mutate('Nombre actualizado', () =>
            patchFile(file, { name: renameName }),
        );

        if (renamed) {
            showRename = false;
            contextFile = null;
        }
    }

    async function applyMove(): Promise<void> {
        if (!contextFile) {
            return;
        }

        const file = contextFile;
        const moved = await mutate('Elemento movido', () =>
            patchFile(file, { parent_code: moveTarget === 'root' ? null : moveTarget }),
        );

        if (moved) {
            showMove = false;
            contextFile = null;
        }
    }

    async function moveInto(file: DriveItem, folder: DriveItem): Promise<void> {
        await mutate('Elemento movido', () => patchFile(file, { parent_code: folder.code }));
    }

    // Trashing, restoring, and deleting always take the row out of the list it
    // was acted on from, so the list is settled without asking the server again.
    async function setTrashed(file: DriveItem, trashed: boolean): Promise<void> {
        await mutate(
            trashed ? 'Movido a la papelera' : 'Elemento restaurado',
            () => patchFile(file, { trashed }),
            () => dropRow(file.code),
        );
    }

    async function destroyFile(file: DriveItem): Promise<void> {
        await mutate(
            'Eliminado permanentemente',
            async () => {
                const payload = await request<{ storage: DriveStorageInfo }>(
                    `/drive/files/${file.code}`,
                    { method: 'DELETE' },
                );
                storageInfo = payload.storage;
            },
            () => dropRow(file.code),
        );
    }

    async function emptyTrash(): Promise<void> {
        await mutate(
            'Papelera vaciada',
            async () => {
                const payload = await request<{ storage: DriveStorageInfo }>('/drive/trash', {
                    method: 'DELETE',
                });
                storageInfo = payload.storage;
            },
            () => (files = []),
        );
    }

    async function loadShares(file: DriveItem): Promise<void> {
        const payload = await request<{ shares: DriveShareRow[] }>(
            `/drive/files/${file.code}/shares`,
        );
        shareRows = payload.shares;
    }

    async function addShare(): Promise<void> {
        if (!shareFile || !shareRecipient) {
            return;
        }

        shareBusy = true;

        try {
            const payload = await request<{ shares: DriveShareRow[] }>(
                `/drive/files/${shareFile.code}/shares`,
                {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user_code: shareRecipient }),
                },
            );
            shareRows = payload.shares;
            shareRecipient = null;
            feedback = { color: 'success', message: 'Archivo compartido' };
        } catch (caught) {
            feedback = {
                color: 'danger',
                message: caught instanceof Error ? caught.message : 'No se pudo compartir.',
            };
        } finally {
            shareBusy = false;
        }
    }

    async function removeShare(share: DriveShareRow): Promise<void> {
        if (!shareFile) {
            return;
        }

        shareBusy = true;

        try {
            const payload = await request<{ shares: DriveShareRow[] }>(
                `/drive/files/${shareFile.code}/shares/${share.code}`,
                { method: 'DELETE' },
            );
            shareRows = payload.shares;
            feedback = { color: 'success', message: 'Acceso retirado' };
        } catch (caught) {
            feedback = {
                color: 'danger',
                message: caught instanceof Error ? caught.message : 'No se pudo quitar el acceso.',
            };
        } finally {
            shareBusy = false;
        }
    }

    function openRenameDialog(file: DriveItem): void {
        contextFile = file;
        renameName = file.name;
        showRename = true;
        fileMenu?.close();
    }

    async function openMoveDialog(file: DriveItem): Promise<void> {
        contextFile = file;
        moveTarget = file.parent_code ?? 'root';
        fileMenu?.close();

        try {
            await loadFolderOptions(file.type === 'dir' ? file.code : null);
            showMove = true;
        } catch (caught) {
            feedback = {
                color: 'danger',
                message:
                    caught instanceof Error
                        ? caught.message
                        : 'No se pudieron cargar las carpetas.',
            };
        }
    }

    async function openShareDialog(file: DriveItem): Promise<void> {
        shareFile = file;
        shareRows = [];
        shareRecipient = null;
        showShare = true;
        fileMenu?.close();

        try {
            await loadShares(file);
        } catch (caught) {
            feedback = {
                color: 'danger',
                message:
                    caught instanceof Error ? caught.message : 'No se pudieron cargar los accesos.',
            };
        }
    }

    function confirmDelete(file: DriveItem): void {
        fileMenu?.close();
        confirmation = {
            title: 'Eliminar permanentemente',
            message: `"${file.name}" y todo su contenido se eliminarán sin posibilidad de recuperación.`,
            run: () => destroyFile(file),
        };
    }

    function confirmEmptyTrash(): void {
        confirmation = {
            title: 'Vaciar la papelera',
            message:
                'Todo lo que está en la papelera se eliminará sin posibilidad de recuperación.',
            run: emptyTrash,
        };
    }

    async function runConfirmation(): Promise<void> {
        const pending = confirmation;
        confirmation = null;
        await pending?.run();
    }

    function handleDoubleClick(file: DriveItem): void {
        if (file.type === 'dir') {
            if (!file.deleted_at) {
                openFolder(file);
            }

            return;
        }

        previewFile = file;
        showPreview = true;
    }

    function handleContextMenu(event: MouseEvent, file: DriveItem): void {
        contextFile = file;
        fileMenu?.open(event, file);
    }

    function handleDrop(event: DragEvent, target: DriveItem): void {
        const payload = event.dataTransfer?.getData('application/json');

        if (!payload || target.type !== 'dir' || !canWriteHere) {
            return;
        }

        try {
            const dragged = JSON.parse(payload) as DriveItem;
            if (dragged.code !== target.code && dragged.scope === 'user_private') {
                void moveInto(dragged, target);
            }
        } catch {
            // Ignore malformed drag payloads.
        }
    }

    function download(file: DriveItem): void {
        window.open(serveUrl(file.code, { download: true }), '_blank', 'noopener,noreferrer');
    }

    onMount(() => {
        void loadFiles();
    });

    onDestroy(() => clearTimeout(searchTimer));
</script>

<svelte:head><title>Drive</title></svelte:head>

<div class="lumi-stack lumi-stack--lg">
    <PageHeader title={pageTitle} subtitle="Tu espacio privado de archivos" icon="hardDrive">
        {#snippet actions()}
            <div
                class="lumi-flex lumi-flex--gap-sm lumi-align-items--center lumi-page-sidebar__header-actions"
            >
                <Button
                    variant="ghost"
                    size="sm"
                    icon="slidersHorizontal"
                    class="lumi-page-sidebar__mobile-trigger"
                    onclick={() => (showMobileSidebar = true)}
                    aria-label="Abrir navegación"
                />
                {#if canWriteHere}
                    <Button
                        variant="gradient"
                        color="primary"
                        icon="upload"
                        onclick={() => (showUploader = true)}
                    >
                        Subir
                    </Button>
                    <Button
                        variant="border"
                        color="success"
                        icon="folderPlus"
                        onclick={() => (showCreateFolder = true)}
                    >
                        Carpeta
                    </Button>
                {:else if isTrashView && files.length > 0}
                    <Button
                        variant="filled"
                        color="danger"
                        size="sm"
                        icon="trash"
                        loading={busy}
                        onclick={confirmEmptyTrash}
                    >
                        Vaciar papelera
                    </Button>
                {/if}
            </div>
        {/snippet}
    </PageHeader>

    {#snippet driveNavigation(closeAfterSelection: boolean)}
        <DriveSidebar
            selectedMenu={sidebarMenu}
            storageInfo={{
                used: storageInfo.used,
                total: storageInfo.total,
                percentage: storageInfo.percentage,
            }}
            showScopeControl={false}
            closable={closeAfterSelection}
            onmenuselect={(menu) => {
                selectView((menu?.value as DriveView | undefined) ?? 'folder');
                if (closeAfterSelection) showMobileSidebar = false;
            }}
            onclose={() => (showMobileSidebar = false)}
        />

        <div class="lumi-page-sidebar__section">
            <p class="lumi-page-sidebar__label">Compartidos</p>
            <List size="sm" color="primary">
                {#each SHARE_VIEWS as entry (entry.view)}
                    <ListItem
                        title={entry.name}
                        subtitle={entry.subtitle}
                        icon={entry.icon}
                        clickable
                        active={view === entry.view}
                        onclick={() => {
                            selectView(entry.view);
                            if (closeAfterSelection) showMobileSidebar = false;
                        }}
                    />
                {/each}
            </List>
        </div>
    {/snippet}

    <div class="lumi-layout--two-columns lumi-page-sidebar-layout">
        <PageSidebar
            bind:mobileOpen={showMobileSidebar}
            mobileAriaLabel="Cerrar navegación"
            hideMobileHeader
        >
            {#snippet sidebar()}
                {@render driveNavigation(false)}
            {/snippet}
            {#snippet drawer()}
                {@render driveNavigation(true)}
            {/snippet}
        </PageSidebar>

        <section class="lumi-layout--content-right">
            <div class="lumi-stack lumi-stack--sm">
                <Card spaced>
                    <div
                        class="lumi-flex lumi-flex--gap-sm lumi-flex--mobile-column lumi-align-items--center"
                    >
                        <div class="lumi-flex-item--no-shrink lumi-width--tablet-full">
                            <SegmentedControl
                                value={viewMode}
                                options={[
                                    { value: 'grid', icon: 'grid' },
                                    { value: 'list', icon: 'list' },
                                ]}
                                fullWidth
                                onchange={(value) => {
                                    if (value === 'grid' || value === 'list') {
                                        viewMode = value;
                                    }
                                }}
                                aria-label="Vista de archivos"
                            />
                        </div>
                        <div class="lumi-flex-item--grow lumi-min-width--0 lumi-width--tablet-full">
                            <Input
                                placeholder="Buscar en mi unidad..."
                                icon="search"
                                value={searchQuery}
                                oninput={(event) =>
                                    handleSearchInput(
                                        (event.currentTarget as HTMLInputElement | null)?.value ??
                                            '',
                                    )}
                            />
                        </div>
                    </div>
                </Card>

                <div class="lumi-stack lumi-stack--sm lumi-padding--sm">
                    {#if isFolderView && breadcrumbs.length > 1}
                        <nav
                            class="lumi-flex lumi-flex--gap-xs lumi-align-items--center lumi-flex--wrap"
                        >
                            {#each breadcrumbs as crumb, index (crumb.code || 'root')}
                                {#if index > 0}
                                    <span class="lumi-text--muted">/</span>
                                {/if}
                                {#if index === breadcrumbs.length - 1}
                                    <span class="lumi-font--medium">{crumb.name}</span>
                                {:else}
                                    <Button
                                        variant="flat"
                                        size="sm"
                                        onclick={() => navigateCrumb(crumb)}
                                    >
                                        {crumb.name}
                                    </Button>
                                {/if}
                            {/each}
                        </nav>
                    {/if}

                    {#if feedback}
                        <Alert color={feedback.color} closable onclose={() => (feedback = null)}>
                            {feedback.message}
                        </Alert>
                    {/if}

                    {#if loading}
                        <div
                            class="lumi-flex lumi-flex--column lumi-flex--center lumi-flex--gap-md lumi-padding--xl"
                        >
                            <Loading size="lg" color="primary" />
                            <span class="lumi-text--sm lumi-text--muted">Cargando archivos...</span>
                        </div>
                    {:else if files.length === 0}
                        <EmptyState
                            title={emptyStateCopy.title}
                            description={emptyStateCopy.description}
                            icon={isTrashView ? 'trash' : 'hardDrive'}
                            iconColor="muted"
                        >
                            {#snippet actions()}
                                {#if canWriteHere}
                                    <Button
                                        variant="filled"
                                        color="primary"
                                        icon="upload"
                                        size="sm"
                                        onclick={() => (showUploader = true)}
                                    >
                                        Subir archivos
                                    </Button>
                                {/if}
                            {/snippet}
                        </EmptyState>
                    {:else if viewMode === 'grid'}
                        <DriveFileGrid
                            {files}
                            selectedFiles={[]}
                            isTrash={isTrashView}
                            onfiledblclick={handleDoubleClick}
                            onfilecontextmenu={handleContextMenu}
                            onfiledrop={handleDrop}
                            {serveUrl}
                        />
                    {:else}
                        <DriveFileList
                            {files}
                            selectedFiles={[]}
                            isTrash={isTrashView}
                            onfiledblclick={handleDoubleClick}
                            onfilecontextmenu={handleContextMenu}
                            onfiledrop={handleDrop}
                        />
                    {/if}
                </div>
            </div>
        </section>
    </div>
</div>

<Dialog bind:open={showCreateFolder} title="Nueva carpeta" size="sm">
    <Input
        bind:value={folderName}
        name="folder-name"
        label="Nombre de la carpeta"
        placeholder="Ingresa el nombre"
        required
    />
    {#snippet footer()}
        <Button
            variant="border"
            onclick={() => {
                showCreateFolder = false;
                folderName = '';
            }}
        >
            Cancelar
        </Button>
        <Button
            variant="filled"
            disabled={!folderName.trim()}
            loading={busy}
            onclick={() => void createFolder()}
        >
            Crear
        </Button>
    {/snippet}
</Dialog>

<Dialog bind:open={showRename} title="Renombrar" size="sm">
    <Input
        bind:value={renameName}
        name="rename"
        label="Nuevo nombre"
        placeholder="Ingresa el nombre"
        required
    />
    {#snippet footer()}
        <Button variant="border" onclick={() => (showRename = false)}>Cancelar</Button>
        <Button
            variant="filled"
            disabled={!renameName.trim()}
            loading={busy}
            onclick={() => void renameFile()}
        >
            Renombrar
        </Button>
    {/snippet}
</Dialog>

<Dialog bind:open={showMove} title="Mover a" size="sm">
    <Select
        label="Carpeta destino"
        value={moveTarget}
        options={moveOptions}
        clearable={false}
        onchange={(value) => {
            moveTarget = typeof value === 'string' ? value : 'root';
        }}
    />
    {#snippet footer()}
        <Button variant="border" onclick={() => (showMove = false)}>Cancelar</Button>
        <Button variant="filled" loading={busy} onclick={() => void applyMove()}>Mover</Button>
    {/snippet}
</Dialog>

<Dialog bind:open={showShare} title="Compartir archivo" size="lg" scrollable>
    {#if shareFile}
        <div class="lumi-stack lumi-stack--md">
            <div class="lumi-stack lumi-stack--2xs">
                <span class="lumi-font--medium lumi-text-ellipsis" title={shareFile.name}>
                    {shareFile.name}
                </span>
                <span class="lumi-text--xs lumi-text--muted">
                    {formatFileSize(shareFile.size)} · solo lectura para quien lo recibe
                </span>
            </div>

            <div class="lumi-flex lumi-flex--gap-sm lumi-flex--mobile-column lumi-align-items--end">
                <div class="lumi-flex-item--grow lumi-min-width--0 lumi-width--tablet-full">
                    <Select
                        label="Compartir con"
                        placeholder="Selecciona un usuario"
                        value={shareRecipient}
                        options={availableRecipients.map((recipient) => ({
                            value: recipient.code,
                            label: recipient.full_name,
                        }))}
                        autocomplete
                        noDataText="No hay usuarios disponibles"
                        onchange={(value) => {
                            shareRecipient = typeof value === 'string' ? value : null;
                        }}
                    />
                </div>
                <Button
                    variant="filled"
                    color="primary"
                    icon="share"
                    disabled={!shareRecipient}
                    loading={shareBusy}
                    onclick={() => void addShare()}
                >
                    Compartir
                </Button>
            </div>

            <div class="lumi-flex lumi-align-items--center lumi-flex--gap-sm">
                <span class="lumi-font--medium">Con acceso</span>
                <Chip color="info" size="sm">{shareRows.length}</Chip>
            </div>

            {#if shareRows.length === 0}
                <EmptyState
                    title="Nadie tiene acceso"
                    description="Elige un usuario para darle acceso de solo lectura."
                    icon="share"
                />
            {:else}
                <Table
                    data={shareRows}
                    hover
                    pagination
                    itemsPerPage={5}
                    rowKey={(row) => row.code}
                >
                    {#snippet thead()}
                        <th>Usuario</th>
                        <th>Desde</th>
                        <th>Acciones</th>
                    {/snippet}
                    {#snippet row({ row })}
                        <td>{row.full_name}</td>
                        <td class="lumi-text--sm lumi-text--muted">
                            {row.created_at ? formatDriveDate(row.created_at) : '—'}
                        </td>
                        <td>
                            <Button
                                variant="flat"
                                size="sm"
                                color="danger"
                                icon="trash"
                                disabled={shareBusy}
                                onclick={() => void removeShare(row)}
                            >
                                Quitar
                            </Button>
                        </td>
                    {/snippet}
                </Table>
            {/if}
        </div>
    {/if}

    {#snippet footer()}
        <Button variant="border" onclick={() => (showShare = false)}>Cerrar</Button>
    {/snippet}
</Dialog>

<Dialog
    open={confirmation !== null}
    title={confirmation?.title ?? ''}
    size="sm"
    onclose={() => (confirmation = null)}
>
    <p class="lumi-text--sm">{confirmation?.message ?? ''}</p>
    {#snippet footer()}
        <Button variant="border" onclick={() => (confirmation = null)}>Cancelar</Button>
        <Button
            variant="filled"
            color="danger"
            loading={busy}
            onclick={() => void runConfirmation()}
        >
            Eliminar
        </Button>
    {/snippet}
</Dialog>

<DriveFileUploader bind:open={showUploader} onupload={uploadFile} oncomplete={() => loadFiles()} />
<DriveFilePreview bind:open={showPreview} file={previewFile} ondownload={download} {serveUrl} />

<Context bind:this={fileMenu} aria-label="Opciones del elemento">
    {#snippet children({ data })}
        {@const item = (data as DriveItem | null) ?? contextFile}
        {#if item}
            {#if isTrashView}
                <ContextItem
                    title="Restaurar"
                    icon="refresh"
                    onclick={() => {
                        void setTrashed(item, false);
                        fileMenu?.close();
                    }}
                />
                <ContextItem
                    title="Eliminar permanente"
                    icon="trash"
                    color="danger"
                    onclick={() => confirmDelete(item)}
                />
            {:else}
                {#if item.type === 'dir'}
                    <ContextItem
                        title="Abrir"
                        icon="folder"
                        onclick={() => {
                            openFolder(item);
                            fileMenu?.close();
                        }}
                    />
                {:else}
                    <ContextItem
                        title="Vista previa"
                        icon="eye"
                        onclick={() => {
                            previewFile = item;
                            showPreview = true;
                            fileMenu?.close();
                        }}
                    />
                    <ContextItem
                        title="Descargar"
                        icon="download"
                        onclick={() => {
                            download(item);
                            fileMenu?.close();
                        }}
                    />
                {/if}

                {#if item.scope === 'user_private'}
                    <ContextItem
                        title="Compartir"
                        icon="share"
                        onclick={() => void openShareDialog(item)}
                    />
                    <ContextItem
                        title="Renombrar"
                        icon="edit"
                        onclick={() => openRenameDialog(item)}
                    />
                    <ContextItem
                        title="Mover"
                        icon="arrowRight"
                        onclick={() => void openMoveDialog(item)}
                    />
                    <ContextItem
                        title="Mover a la papelera"
                        icon="trash"
                        color="danger"
                        onclick={() => {
                            void setTrashed(item, true);
                            fileMenu?.close();
                        }}
                    />
                {/if}
            {/if}
        {/if}
    {/snippet}
</Context>
