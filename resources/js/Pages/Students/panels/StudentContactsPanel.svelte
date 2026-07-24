<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import {
        Button,
        Card,
        Dialog,
        EmptyState,
        Input,
        List,
        ListItem,
        Textarea,
    } from '@lumi-ui/svelte';
    import type { StudentContact } from '@/types/student';

    interface Props {
        studentCode: string;
        contacts: StudentContact[];
        canManage: boolean;
    }

    const { studentCode, contacts, canManage }: Props = $props();

    let formOpen = $state(false);
    let deleteOpen = $state(false);
    let selected = $state<StudentContact | null>(null);
    let form = $state({ name: '', phone: '', note: '' });
    let processing = $state(false);
    let errors = $state<Record<string, string>>({});

    function openCreate(): void {
        selected = null;
        form = { name: '', phone: '', note: '' };
        errors = {};
        formOpen = true;
    }

    function openEdit(contact: StudentContact): void {
        selected = contact;
        form = {
            name: contact.name,
            phone: contact.phone ?? '',
            note: contact.note ?? '',
        };
        errors = {};
        formOpen = true;
    }

    function save(): void {
        if (processing) return;

        const options = {
            preserveScroll: true,
            onStart: () => {
                processing = true;
                errors = {};
            },
            onError: (formErrors: Record<string, string>) => {
                errors = formErrors;
            },
            onSuccess: () => {
                formOpen = false;
            },
            onFinish: () => {
                processing = false;
            },
        };

        if (selected) {
            router.put(`/students/${studentCode}/contacts/${selected.code}`, { ...form }, options);
        } else {
            router.post(`/students/${studentCode}/contacts`, { ...form }, options);
        }
    }

    function confirmDelete(contact: StudentContact): void {
        selected = contact;
        deleteOpen = true;
    }

    function remove(): void {
        if (!selected || processing) return;

        router.delete(`/students/${studentCode}/contacts/${selected.code}`, {
            preserveScroll: true,
            onStart: () => {
                processing = true;
            },
            onSuccess: () => {
                deleteOpen = false;
                selected = null;
            },
            onFinish: () => {
                processing = false;
            },
        });
    }
</script>

<Card title="Contactos" subtitle="Personas de referencia del alumno." spaced>
    <div class="lumi-stack lumi-stack--md">
        {#if canManage}
            <div class="lumi-flex lumi-justify--end">
                <Button type="button" size="sm" icon="plus" onclick={openCreate}>
                    Agregar contacto
                </Button>
            </div>
        {/if}

        {#if contacts.length === 0}
            <EmptyState
                icon="users"
                title="Sin contactos"
                description="Todavía no se han registrado personas de contacto."
            />
        {:else}
            <List>
                {#each contacts as contact (contact.code)}
                    <ListItem
                        title={contact.name}
                        subtitle={[contact.phone, contact.note].filter(Boolean).join(' · ') ||
                            'Sin teléfono ni nota'}
                        icon="user"
                    >
                        {#if canManage}
                            <div class="lumi-flex lumi-flex--gap-xs">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="flat"
                                    icon="edit"
                                    aria-label={`Editar ${contact.name}`}
                                    onclick={() => openEdit(contact)}
                                />
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="flat"
                                    color="danger"
                                    icon="trash"
                                    aria-label={`Eliminar ${contact.name}`}
                                    onclick={() => confirmDelete(contact)}
                                />
                            </div>
                        {/if}
                    </ListItem>
                {/each}
            </List>
        {/if}
    </div>
</Card>

<Dialog
    open={formOpen}
    title={selected ? 'Editar contacto' : 'Nuevo contacto'}
    size="sm"
    persistent={processing}
    onclose={() => (formOpen = false)}
    closeLabel="Cerrar contacto"
>
    <form
        class="lumi-stack lumi-stack--md"
        onsubmit={(event) => {
            event.preventDefault();
            save();
        }}
    >
        <Input
            bind:value={form.name}
            label="Nombre"
            maxlength={150}
            required
            disabled={processing}
            danger={!!errors.name}
            dangerText={errors.name}
        />
        <Input
            bind:value={form.phone}
            label="Teléfono"
            maxlength={30}
            disabled={processing}
            danger={!!errors.phone}
            dangerText={errors.phone}
        />
        <Textarea
            bind:value={form.note}
            label="Nota"
            maxlength={250}
            rows={3}
            showCount
            disabled={processing}
            error={errors.note}
        />
        <div class="lumi-flex lumi-justify--end lumi-flex--gap-sm">
            <Button type="button" variant="border" onclick={() => (formOpen = false)}>
                Cancelar
            </Button>
            <Button type="submit" icon="check" loading={processing}>Guardar</Button>
        </div>
    </form>
</Dialog>

<Dialog
    open={deleteOpen}
    title="Eliminar contacto"
    size="sm"
    persistent={processing}
    onclose={() => (deleteOpen = false)}
>
    <div class="lumi-stack lumi-stack--md">
        <p class="lumi-margin--none">
            Se eliminará a <strong>{selected?.name}</strong> de los contactos del alumno.
        </p>
        <div class="lumi-flex lumi-justify--end lumi-flex--gap-sm">
            <Button type="button" variant="border" onclick={() => (deleteOpen = false)}>
                Cancelar
            </Button>
            <Button type="button" color="danger" icon="trash" loading={processing} onclick={remove}>
                Eliminar
            </Button>
        </div>
    </div>
</Dialog>
