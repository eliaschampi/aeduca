<script lang="ts">
    import { onMount, untrack } from 'svelte';
    import { page, router } from '@inertiajs/svelte';
    import {
        Alert,
        Button,
        Card,
        Chip,
        Dialog,
        DriveFileUploader,
        EmptyState,
        InfoItem,
        Input,
        PageHeader,
        Table,
    } from '@lumi-ui/svelte';
    import { formatFileSize } from '@lumi-ui/svelte/drive';
    import type { DriveItem } from '@/types/drive';
    import type {
        StudentAttentionBranch,
        StudentAttentionDetail,
        StudentAttentionFile,
        StudentAttentionSubject,
    } from '@/types/student-attention';

    interface Props {
        student: StudentAttentionSubject;
        branch: StudentAttentionBranch;
        attention: StudentAttentionDetail;
        files: StudentAttentionFile[];
        business_timezone: string;
        can_manage?: boolean;
        can_use_drive?: boolean;
    }

    const {
        student,
        branch,
        attention,
        files: initialFiles,
        business_timezone,
        can_manage = false,
        can_use_drive = false,
    }: Props = $props();

    let linkedFiles = $state(untrack(() => [...initialFiles]));
    let availableFiles = $state<DriveItem[]>([]);
    let pickerOpen = $state(false);
    let uploaderOpen = $state(false);
    let pickerLoading = $state(false);
    let search = $state('');
    let busyFileCode = $state<string | null>(null);
    let fileToDetach = $state<StudentAttentionFile | null>(null);
    let feedback = $state<{ color: 'danger' | 'success'; message: string } | null>(null);

    const dateFormatter = $derived(
        new Intl.DateTimeFormat('es-PE', {
            timeZone: business_timezone,
            dateStyle: 'long',
            timeStyle: 'short',
        }),
    );

    function formatDate(value: string): string {
        try {
            return dateFormatter.format(new Date(value));
        } catch {
            return value;
        }
    }

    function csrfToken(): string {
        return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    }

    async function request<T>(url: string, init: RequestInit = {}): Promise<T> {
        const response = await fetch(url, {
            credentials: 'same-origin',
            ...init,
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                ...init.headers,
            },
        });
        const payload = (await response.json().catch(() => null)) as {
            message?: string;
            errors?: Record<string, string[]>;
        } | null;

        if (!response.ok) {
            throw new Error(
                Object.values(payload?.errors ?? {})[0]?.[0] ??
                    payload?.message ??
                    'No se pudo completar la operación.',
            );
        }

        return payload as T;
    }

    async function loadDriveFiles(): Promise<void> {
        if (!can_use_drive) return;

        pickerLoading = true;
        feedback = null;
        try {
            const params = new URLSearchParams({ view: 'recent' });
            if (search.trim()) params.set('search', search.trim());
            const payload = await request<{ files: DriveItem[] }>(`/drive/files?${params}`);
            const linkedCodes = new Set(linkedFiles.map((file) => file.code));
            availableFiles = payload.files.filter(
                (file) => file.type !== 'dir' && !linkedCodes.has(file.code),
            );
        } catch (error) {
            feedback = {
                color: 'danger',
                message: error instanceof Error ? error.message : 'No se pudo consultar Drive.',
            };
        } finally {
            pickerLoading = false;
        }
    }

    async function attachFile(fileCode: string): Promise<void> {
        if (busyFileCode) return;

        busyFileCode = fileCode;
        feedback = null;
        try {
            const payload = await request<{ file: StudentAttentionFile }>(
                `/students/${student.code}/attentions/${attention.code}/files`,
                {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ file_code: fileCode }),
                },
            );
            if (!linkedFiles.some((file) => file.code === payload.file.code)) {
                linkedFiles = [payload.file, ...linkedFiles];
            }
            availableFiles = availableFiles.filter((file) => file.code !== payload.file.code);
            feedback = { color: 'success', message: 'Archivo vinculado' };
        } catch (error) {
            feedback = {
                color: 'danger',
                message: error instanceof Error ? error.message : 'No se pudo vincular el archivo.',
            };
            throw error;
        } finally {
            busyFileCode = null;
        }
    }

    async function uploadFile(file: File): Promise<void> {
        const body = new FormData();
        body.append('file', file);
        const payload = await request<{ file: DriveItem }>('/drive/files', {
            method: 'POST',
            body,
        });

        await attachFile(payload.file.code);
    }

    async function detachFile(): Promise<void> {
        if (!fileToDetach || busyFileCode) return;

        const file = fileToDetach;
        busyFileCode = file.code;
        feedback = null;
        try {
            await request(
                `/students/${student.code}/attentions/${attention.code}/files/${file.code}`,
                { method: 'DELETE' },
            );
            linkedFiles = linkedFiles.filter((item) => item.code !== file.code);
            fileToDetach = null;
            feedback = {
                color: 'success',
                message: 'Archivo desvinculado; permanece en el Drive de su propietario.',
            };
        } catch (error) {
            feedback = {
                color: 'danger',
                message: error instanceof Error ? error.message : 'No se pudo desvincular.',
            };
        } finally {
            busyFileCode = null;
        }
    }

    function openPicker(): void {
        search = '';
        pickerOpen = true;
        void loadDriveFiles();
    }

    onMount(() => {
        if (page.flash.open_attachments && can_manage && can_use_drive) openPicker();
    });
</script>

<svelte:head>
    <title>{attention.reason} · Atenciones · {student.full_name} · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title={attention.reason}
        subtitle={`${student.full_name} · DNI ${student.dni} · ${branch.name}`}
        icon="clipboardPenLine"
        size="xl"
    >
        {#snippet actions()}
            <div class="lumi-flex lumi-flex--wrap lumi-flex--gap-sm">
                <Button
                    type="button"
                    variant="border"
                    icon="arrowLeft"
                    onclick={() => router.visit(`/students/${student.code}/attentions`)}
                >
                    Historial
                </Button>
                {#if can_manage}
                    <Button
                        type="button"
                        icon="edit"
                        onclick={() =>
                            router.visit(
                                `/students/${student.code}/attentions/${attention.code}/edit`,
                            )}
                    >
                        Editar
                    </Button>
                {/if}
            </div>
        {/snippet}
    </PageHeader>

    {#if feedback}
        <Alert color={feedback.color}>{feedback.message}</Alert>
    {/if}

    <Card title="Resumen" spaced>
        <div class="lumi-grid lumi-grid--responsive lumi-grid--gap-md">
            <InfoItem label="Tipo" value={attention.type_label} icon="clipboardPenLine" />
            <InfoItem
                label="Fecha de la atención"
                value={formatDate(attention.occurred_at)}
                icon="calendar"
            />
            <InfoItem label="Registrado por" value={attention.author_name} icon="user" />
            <InfoItem
                label="Fecha de registro"
                value={formatDate(attention.created_at)}
                icon="calendar"
            />
            {#if attention.updated_by_name}
                <InfoItem
                    label="Última edición"
                    value={`${attention.updated_by_name} · ${formatDate(attention.updated_at)}`}
                    icon="edit"
                />
            {/if}
        </div>
    </Card>

    <Card
        title="Archivos adjuntos"
        subtitle="Adjunta un archivo existente o súbelo a Drive sin duplicar su contenido."
        spaced
    >
        <div class="lumi-stack lumi-stack--md">
            {#if can_manage && can_use_drive}
                <div class="lumi-flex lumi-flex--wrap lumi-flex--gap-sm">
                    <Button type="button" size="sm" icon="link" onclick={openPicker}>
                        Adjuntar desde Drive
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="border"
                        icon="upload"
                        onclick={() => (uploaderOpen = true)}
                    >
                        Subir y vincular
                    </Button>
                </div>
            {/if}

            {#if linkedFiles.length === 0}
                <EmptyState
                    icon="file"
                    title="Sin archivos vinculados"
                    description={can_manage && !can_use_drive
                        ? 'Necesitas acceso a Drive para agregar archivos.'
                        : 'Esta atención no tiene archivos adjuntos.'}
                />
            {:else}
                <Table
                    data={linkedFiles}
                    rowKey={(file) => file.code}
                    noDataText="No hay archivos."
                    aria-label="Archivos de la atención"
                >
                    {#snippet thead()}
                        <th scope="col">Archivo</th>
                        <th scope="col">Tamaño</th>
                        <th scope="col">Estado</th>
                        <th scope="col" aria-label="Acciones"></th>
                    {/snippet}
                    {#snippet row({ row }: { row: StudentAttentionFile })}
                        <td><span class="lumi-font--medium lumi-text-break">{row.name}</span></td>
                        <td>{formatFileSize(row.size)}</td>
                        <td>
                            <Chip color={row.deleted_at ? 'warning' : 'success'} size="sm">
                                {row.deleted_at ? 'En papelera' : 'Disponible'}
                            </Chip>
                        </td>
                        <td>
                            <div class="lumi-flex lumi-justify--end lumi-flex--gap-xs">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="border"
                                    icon="download"
                                    onclick={() =>
                                        window.open(
                                            `${row.serve_url}?download=1`,
                                            '_blank',
                                            'noopener',
                                        )}
                                    aria-label={`Descargar ${row.name}`}
                                />
                                {#if can_manage}
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="border"
                                        color="danger"
                                        icon="x"
                                        disabled={busyFileCode !== null}
                                        onclick={() => (fileToDetach = row)}
                                        aria-label={`Desvincular ${row.name}`}
                                    />
                                {/if}
                            </div>
                        </td>
                    {/snippet}
                </Table>
            {/if}
        </div>
    </Card>

    <Card title="Desarrollo" spaced>
        <p class="lumi-margin--none lumi-text-pre-line lumi-text-break">
            {attention.development}
        </p>
    </Card>

    <Card title="Conclusión y acuerdos" spaced>
        <p class="lumi-margin--none lumi-text-pre-line lumi-text-break">
            {attention.conclusion}
        </p>
    </Card>
</div>

<Dialog bind:open={pickerOpen} title="Elegir archivo de Drive" size="lg">
    <div class="lumi-stack lumi-stack--md">
        <form
            onsubmit={(event) => {
                event.preventDefault();
                void loadDriveFiles();
            }}
        >
            <div class="lumi-inline-filters lumi-inline-filters--compact">
                <div class="lumi-flex-item--grow lumi-min-width--0">
                    <Input bind:value={search} label="Buscar archivo" icon="search" />
                </div>
                <Button type="submit" icon="search" loading={pickerLoading} />
            </div>
        </form>

        {#if availableFiles.length === 0 && !pickerLoading}
            <EmptyState
                icon="file"
                title="Sin archivos disponibles"
                description="Sube un archivo nuevo o busca con otro nombre."
            />
        {:else}
            <Table
                data={availableFiles}
                rowKey={(file) => file.code}
                noDataText="No hay archivos."
                aria-label="Archivos disponibles en Drive"
            >
                {#snippet thead()}
                    <th scope="col">Archivo</th>
                    <th scope="col">Tamaño</th>
                    <th scope="col" aria-label="Vincular"></th>
                {/snippet}
                {#snippet row({ row }: { row: DriveItem })}
                    <td><span class="lumi-font--medium lumi-text-break">{row.name}</span></td>
                    <td>{formatFileSize(row.size)}</td>
                    <td>
                        <Button
                            type="button"
                            size="sm"
                            icon="link"
                            loading={busyFileCode === row.code}
                            disabled={busyFileCode !== null}
                            onclick={() => void attachFile(row.code)}
                        >
                            Vincular
                        </Button>
                    </td>
                {/snippet}
            </Table>
        {/if}
    </div>
</Dialog>

<DriveFileUploader
    bind:open={uploaderOpen}
    onupload={uploadFile}
    oncomplete={() => {
        uploaderOpen = false;
    }}
/>

<Dialog
    open={fileToDetach !== null}
    title="Desvincular archivo"
    size="sm"
    persistent={busyFileCode !== null}
    onclose={() => {
        if (!busyFileCode) fileToDetach = null;
    }}
>
    <div class="lumi-stack lumi-stack--md">
        <p class="lumi-margin--none">
            Se quitará <strong>{fileToDetach?.name}</strong> de esta atención. El archivo permanecerá
            en Drive.
        </p>
        <div class="lumi-flex lumi-justify--end lumi-flex--gap-sm">
            <Button
                type="button"
                variant="border"
                disabled={busyFileCode !== null}
                onclick={() => (fileToDetach = null)}
            >
                Cancelar
            </Button>
            <Button
                type="button"
                color="danger"
                icon="x"
                loading={busyFileCode !== null}
                onclick={() => void detachFile()}
            >
                Desvincular
            </Button>
        </div>
    </div>
</Dialog>
