<script lang="ts">
    import {
        Alert,
        Button,
        Dialog,
        EmptyState,
        FileUpload,
        Input,
        List,
        ListItem,
        SegmentedControl,
        Tooltip,
        type FileUploadFile,
    } from '@lumi-ui/svelte';
    import { formatFileSize, isAllowedMimeType, MAX_FILE_SIZE } from '@lumi-ui/svelte/drive';
    import type { DriveItem } from '@/types/drive';
    import type { StudentAttentionAttachment } from '@/types/student-attention';

    interface Props {
        attachment?: StudentAttentionAttachment | null;
        canUseDrive?: boolean;
        disabled?: boolean;
        error?: string;
    }

    let {
        attachment = $bindable(null),
        canUseDrive = false,
        disabled = false,
        error = '',
    }: Props = $props();

    let dialogOpen = $state(false);
    let mode = $state<'drive' | 'upload'>('drive');
    let search = $state('');
    let driveFiles = $state<DriveItem[]>([]);
    let uploadFiles = $state<FileUploadFile[]>([]);
    let loadingDrive = $state(false);
    let uploading = $state(false);
    let dialogError = $state('');

    const modes = [
        { value: 'drive', label: 'Mi Drive', icon: 'hardDrive' },
        { value: 'upload', label: 'Subir archivo', icon: 'upload' },
    ];

    function csrfToken(): string {
        return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    }

    function openDialog(): void {
        if (!canUseDrive || disabled) return;

        mode = 'drive';
        search = '';
        dialogError = '';
        dialogOpen = true;
        void loadDriveFiles();
    }

    function closeDialog(): void {
        if (uploading) return;

        dialogOpen = false;
        dialogError = '';
        uploadFiles = [];
    }

    async function loadDriveFiles(): Promise<void> {
        loadingDrive = true;
        dialogError = '';

        try {
            const params = new URLSearchParams({ view: 'recent' });
            if (search.trim()) params.set('search', search.trim());
            const response = await fetch(`/drive/files?${params}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const payload = (await response.json()) as { files?: DriveItem[]; message?: string };

            if (!response.ok) {
                throw new Error(payload.message ?? 'No se pudo consultar Drive.');
            }

            driveFiles = (payload.files ?? []).filter((file) => file.type !== 'dir');
        } catch (caught) {
            driveFiles = [];
            dialogError = caught instanceof Error ? caught.message : 'No se pudo consultar Drive.';
        } finally {
            loadingDrive = false;
        }
    }

    function selectDriveFile(file: DriveItem): void {
        attachment = {
            code: file.code,
            name: file.name,
            size: file.size,
            deleted_at: null,
            serve_url: null,
        };
        closeDialog();
    }

    async function uploadFile(): Promise<void> {
        const selected = uploadFiles[0];
        if (!selected || uploading) return;

        uploading = true;
        dialogError = '';
        uploadFiles = uploadFiles.map((item) =>
            item.id === selected.id ? { ...item, status: 'uploading' } : item,
        );

        try {
            const body = new FormData();
            body.append('file', selected.file);
            const response = await fetch('/student-attentions/attachment', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body,
            });
            const payload = (await response.json().catch(() => null)) as {
                attachment?: StudentAttentionAttachment;
                message?: string;
                errors?: Record<string, string[]>;
            } | null;

            if (!response.ok || !payload?.attachment) {
                throw new Error(
                    Object.values(payload?.errors ?? {})[0]?.[0] ??
                        payload?.message ??
                        'No se pudo subir el archivo.',
                );
            }

            attachment = payload.attachment;
            uploadFiles = uploadFiles.map((item) =>
                item.id === selected.id ? { ...item, status: 'success', progress: 100 } : item,
            );
            dialogOpen = false;
            uploadFiles = [];
        } catch (caught) {
            uploadFiles = uploadFiles.map((item) =>
                item.id === selected.id
                    ? {
                          ...item,
                          status: 'error',
                          error:
                              caught instanceof Error
                                  ? caught.message
                                  : 'No se pudo subir el archivo.',
                      }
                    : item,
            );
            dialogError = caught instanceof Error ? caught.message : 'No se pudo subir el archivo.';
        } finally {
            uploading = false;
        }
    }

    function validateUpload(file: File): string | null {
        return isAllowedMimeType(file.type) ? null : 'Tipo de archivo no permitido.';
    }
</script>

<div class="lumi-stack lumi-stack--sm">
    {#if attachment}
        <List size="sm" color={attachment.deleted_at ? 'warning' : 'primary'}>
            <ListItem
                title={attachment.name}
                subtitle={`${formatFileSize(attachment.size)}${attachment.deleted_at ? ' · En papelera' : ''}`}
                icon="fileText"
            >
                {#if attachment.serve_url}
                    <Tooltip text="Descargar adjunto">
                        <Button
                            type="button"
                            size="sm"
                            variant="flat"
                            icon="download"
                            aria-label={`Descargar ${attachment.name}`}
                            onclick={() =>
                                window.open(
                                    `${attachment?.serve_url}?download=1`,
                                    '_blank',
                                    'noopener,noreferrer',
                                )}
                        />
                    </Tooltip>
                {/if}
                <Tooltip text="Quitar adjunto" color="danger">
                    <Button
                        type="button"
                        size="sm"
                        variant="flat"
                        color="danger"
                        icon="x"
                        {disabled}
                        aria-label={`Quitar ${attachment.name}`}
                        onclick={() => (attachment = null)}
                    />
                </Tooltip>
            </ListItem>
        </List>
    {/if}

    {#if canUseDrive}
        <div>
            <Button
                type="button"
                variant="border"
                size="sm"
                icon="link"
                {disabled}
                onclick={openDialog}
            >
                {attachment ? 'Cambiar adjunto' : 'Adjuntar archivo'}
            </Button>
        </div>
    {:else if !attachment}
        <p class="lumi-margin--none lumi-text--sm lumi-text--muted">
            Necesitas acceso a Drive para adjuntar un archivo.
        </p>
    {/if}

    {#if error}
        <Alert color="danger">{error}</Alert>
    {/if}
</div>

{#if canUseDrive}
    <Dialog
        bind:open={dialogOpen}
        title="Adjuntar archivo"
        size="lg"
        persistent={uploading}
        onclose={closeDialog}
    >
        <div class="lumi-stack lumi-stack--md">
            <SegmentedControl
                bind:value={mode}
                options={modes}
                fullWidth
                aria-label="Origen del archivo"
            />

            {#if dialogError}
                <Alert color="danger">{dialogError}</Alert>
            {/if}

            {#if mode === 'drive'}
                <form
                    class="lumi-inline-filters lumi-inline-filters--compact"
                    onsubmit={(event) => {
                        event.preventDefault();
                        void loadDriveFiles();
                    }}
                >
                    <div class="lumi-flex-item--grow lumi-min-width--0">
                        <Input
                            bind:value={search}
                            label="Buscar en Drive"
                            placeholder="Nombre del archivo"
                            icon="search"
                            disabled={loadingDrive}
                        />
                    </div>
                    <Button
                        type="submit"
                        variant="border"
                        icon="search"
                        loading={loadingDrive}
                        aria-label="Buscar archivos"
                    />
                </form>

                {#if driveFiles.length === 0 && !loadingDrive}
                    <EmptyState
                        icon="file"
                        title="Sin archivos disponibles"
                        description={search
                            ? 'No hay coincidencias con ese nombre.'
                            : 'Sube un archivo nuevo para adjuntarlo.'}
                    />
                {:else}
                    <List size="sm" maxHeight="lg">
                        {#each driveFiles as file (file.code)}
                            <ListItem
                                title={file.name}
                                subtitle={formatFileSize(file.size)}
                                icon="fileText"
                                clickable
                                onclick={() => selectDriveFile(file)}
                            />
                        {/each}
                    </List>
                {/if}
            {:else}
                <div class="lumi-stack lumi-stack--md">
                    <FileUpload
                        bind:files={uploadFiles}
                        multiple={false}
                        maxSize={MAX_FILE_SIZE}
                        disabled={uploading}
                        validateFile={validateUpload}
                        placeholderText="Selecciona un archivo"
                        hintText="Se guardará en la carpeta Atenciones de tu Drive"
                        maxSizeErrorMessage={() => 'El archivo supera el tamaño máximo de 50 MB.'}
                    />
                    <div class="lumi-flex lumi-justify--end">
                        <Button
                            type="button"
                            icon="upload"
                            loading={uploading}
                            disabled={uploadFiles.length !== 1}
                            onclick={() => void uploadFile()}
                        >
                            Subir y adjuntar
                        </Button>
                    </div>
                </div>
            {/if}
        </div>

        {#snippet footer()}
            <Button type="button" variant="border" disabled={uploading} onclick={closeDialog}>
                Cerrar
            </Button>
        {/snippet}
    </Dialog>
{/if}
