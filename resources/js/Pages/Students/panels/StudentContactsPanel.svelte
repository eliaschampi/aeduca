<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import {
        Alert,
        Button,
        Dialog,
        EmptyState,
        Input,
        List,
        ListItem,
        Textarea,
        Title,
    } from '@lumi-ui/svelte';
    import type { StudentContactData } from '@/types/student';

    interface Props {
        studentCode: string;
        contacts: StudentContactData[];
        canManage: boolean;
    }

    interface ContactForm {
        name: string;
        phone: string;
        note: string;
    }

    const { studentCode, contacts, canManage }: Props = $props();
    let contactDialogOpen = $state(false);
    let editingContact = $state<StudentContactData | null>(null);
    let contactForm = $state<ContactForm>({ name: '', phone: '', note: '' });
    let contactProcessing = $state(false);
    let contactErrors = $state<Record<string, string>>({});
    let contactError = $state<string | null>(null);
    let deleteDialogOpen = $state(false);
    let contactToDelete = $state<StudentContactData | null>(null);
    let deleteProcessing = $state(false);
    let deleteError = $state<string | null>(null);

    function contactMeta(contact: StudentContactData): string {
        return (
            [contact.phone, contact.note].filter(Boolean).join(' · ') || 'Sin información adicional'
        );
    }

    function openCreate(): void {
        if (!canManage) return;
        editingContact = null;
        contactForm = { name: '', phone: '', note: '' };
        contactErrors = {};
        contactError = null;
        contactDialogOpen = true;
    }

    function openEdit(contact: StudentContactData): void {
        if (!canManage) return;
        editingContact = contact;
        contactForm = {
            name: contact.name,
            phone: contact.phone ?? '',
            note: contact.note ?? '',
        };
        contactErrors = {};
        contactError = null;
        contactDialogOpen = true;
    }

    function closeContactDialog(): void {
        if (contactProcessing) return;
        contactDialogOpen = false;
    }

    function saveContact(): void {
        if (contactProcessing) return;

        const options = {
            preserveScroll: true,
            onStart: () => {
                contactProcessing = true;
                contactErrors = {};
                contactError = null;
            },
            onError: (errors: Record<string, string>) => {
                contactErrors = errors;
                contactError = errors.message ?? null;
            },
            onSuccess: () => {
                contactDialogOpen = false;
            },
            onFinish: () => {
                contactProcessing = false;
            },
        };

        if (editingContact) {
            router.put(
                `/students/${studentCode}/contacts/${editingContact.code}`,
                { ...contactForm },
                options,
            );
            return;
        }

        router.post(`/students/${studentCode}/contacts`, { ...contactForm }, options);
    }

    function openDelete(contact: StudentContactData): void {
        if (!canManage) return;
        contactToDelete = contact;
        deleteError = null;
        deleteDialogOpen = true;
    }

    function closeDeleteDialog(): void {
        if (deleteProcessing) return;
        deleteDialogOpen = false;
        contactToDelete = null;
    }

    function deleteContact(): void {
        if (!contactToDelete || deleteProcessing) return;

        router.delete(`/students/${studentCode}/contacts/${contactToDelete.code}`, {
            preserveScroll: true,
            onStart: () => {
                deleteProcessing = true;
                deleteError = null;
            },
            onError: (errors: Record<string, string>) => {
                deleteError = errors.message ?? 'No se pudo eliminar el contacto.';
            },
            onSuccess: () => {
                deleteDialogOpen = false;
                contactToDelete = null;
            },
            onFinish: () => {
                deleteProcessing = false;
            },
        });
    }
</script>

<div class="lumi-stack lumi-stack--md">
    <div
        class="lumi-flex lumi-align-items--center lumi-justify--between lumi-flex--gap-md lumi-flex--wrap"
    >
        <Title
            title="Contactos de referencia"
            subtitle="Personas registradas para comunicarse sobre el estudiante."
            icon="users"
            size="sm"
            level={2}
        />
        {#if canManage}
            <Button type="button" size="sm" icon="plus" onclick={openCreate}>
                Agregar contacto
            </Button>
        {/if}
    </div>

    {#if contacts.length === 0}
        <EmptyState
            icon="users"
            title="Sin contactos registrados"
            description="Esta ficha todavía no tiene personas de referencia."
        >
            {#snippet actions()}
                {#if canManage}
                    <Button type="button" variant="border" icon="plus" onclick={openCreate}>
                        Agregar contacto
                    </Button>
                {/if}
            {/snippet}
        </EmptyState>
    {:else}
        <List size="md" color="primary">
            {#each contacts as contact (contact.code)}
                <ListItem icon="userRound" title={contact.name} subtitle={contactMeta(contact)}>
                    {#if canManage}
                        <div class="lumi-flex lumi-flex--gap-xs">
                            <Button
                                type="button"
                                variant="flat"
                                color="info"
                                size="sm"
                                icon="edit"
                                aria-label={`Editar contacto ${contact.name}`}
                                onclick={() => openEdit(contact)}
                            />
                            <Button
                                type="button"
                                variant="flat"
                                color="danger"
                                size="sm"
                                icon="trash"
                                aria-label={`Eliminar contacto ${contact.name}`}
                                onclick={() => openDelete(contact)}
                            />
                        </div>
                    {/if}
                </ListItem>
            {/each}
        </List>
    {/if}
</div>

{#if canManage}
    <Dialog
        open={contactDialogOpen}
        title={editingContact ? 'Editar contacto' : 'Agregar contacto'}
        size="sm"
        persistent={contactProcessing}
        onclose={closeContactDialog}
    >
        <form
            class="lumi-stack lumi-stack--md"
            aria-busy={contactProcessing}
            onsubmit={(event) => {
                event.preventDefault();
                saveContact();
            }}
        >
            {#if contactError}
                <Alert color="danger">{contactError}</Alert>
            {/if}

            <Input
                label="Nombre"
                placeholder="Ej. Rosa Mamani Huamán"
                bind:value={contactForm.name}
                maxlength={150}
                required
                danger={Boolean(contactErrors.name)}
                dangerText={contactErrors.name}
            />
            <Input
                label="Teléfono"
                type="tel"
                placeholder="Ej. 987 654 321"
                bind:value={contactForm.phone}
                maxlength={50}
                danger={Boolean(contactErrors.phone)}
                dangerText={contactErrors.phone}
            />
            <Textarea
                label="Nota"
                placeholder="Ej. Madre; llamar por las tardes"
                bind:value={contactForm.note}
                rows={3}
                error={contactErrors.note ?? false}
            />

            <div class="lumi-flex lumi-justify--end lumi-flex--gap-sm">
                <Button
                    type="button"
                    variant="border"
                    disabled={contactProcessing}
                    onclick={closeContactDialog}
                >
                    Cancelar
                </Button>
                <Button type="submit" icon="check" loading={contactProcessing}>Guardar</Button>
            </div>
        </form>
    </Dialog>

    <Dialog
        open={deleteDialogOpen}
        title="Eliminar contacto"
        size="sm"
        persistent={deleteProcessing}
        onclose={closeDeleteDialog}
    >
        <div class="lumi-stack lumi-stack--md">
            {#if deleteError}
                <Alert color="danger">{deleteError}</Alert>
            {/if}

            <p class="lumi-margin--none lumi-text--sm">
                ¿Eliminar a <strong>{contactToDelete?.name}</strong> de los contactos del estudiante?
            </p>

            <div class="lumi-flex lumi-justify--end lumi-flex--gap-sm">
                <Button
                    type="button"
                    variant="border"
                    disabled={deleteProcessing}
                    onclick={closeDeleteDialog}
                >
                    Cancelar
                </Button>
                <Button
                    type="button"
                    color="danger"
                    icon="trash"
                    loading={deleteProcessing}
                    onclick={deleteContact}
                >
                    Eliminar
                </Button>
            </div>
        </div>
    </Dialog>
{/if}
