<script lang="ts">
    import { untrack } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import {
        Alert,
        Button,
        Card,
        Chip,
        Dialog,
        Divider,
        Dropdown,
        DropdownItem,
        InfoItem,
        PageHeader,
        Tabs,
        UserInfo,
    } from '@lumi-ui/svelte';
    import StudentPhotoCropper from './components/StudentPhotoCropper.svelte';
    import StudentContactsPanel from './panels/StudentContactsPanel.svelte';
    import StudentEnrollmentsPanel from './panels/StudentEnrollmentsPanel.svelte';
    import type {
        EnrollmentSummary,
        StudentAccess,
        StudentContact,
        StudentProfile,
    } from '@/types/student';

    type ProfileTab = 'summary' | 'contacts' | 'enrollments';
    type AccessOperation = 'enable' | 'reset' | 'disable';

    interface TemporaryCredential {
        login: string;
        temporary_password: string;
    }

    interface AccessResponse {
        message: string;
        credential: TemporaryCredential | null;
        errors?: Record<string, string[]>;
    }

    interface Props {
        student: StudentProfile;
        access: StudentAccess | null;
        contacts: StudentContact[];
        enrollments: EnrollmentSummary[];
        enrollment_count: number;
        is_self?: boolean;
        can_manage?: boolean;
        can_manage_enrollments?: boolean;
    }

    const {
        student,
        access,
        contacts,
        enrollments,
        enrollment_count,
        is_self = false,
        can_manage = false,
        can_manage_enrollments = false,
    }: Props = $props();

    const fullName = $derived(`${student.first_name} ${student.last_name}`.trim());
    const currentEnrollment = $derived(
        enrollments.find((enrollment) => enrollment.status === 'active') ?? null,
    );
    const editableEnrollment = $derived(
        enrollments.find((enrollment) => enrollment.status !== 'finalized') ?? null,
    );
    const tabs = $derived([
        { value: 'summary', label: 'Acceso', icon: 'key' },
        ...(!is_self ? [{ value: 'contacts', label: 'Contactos', icon: 'users' }] : []),
        { value: 'enrollments', label: 'Matrículas', icon: 'bookOpen' },
    ]);

    let activeTab = $state<ProfileTab>('summary');
    let accessState = $state<StudentAccess | null>(untrack(() => (access ? { ...access } : null)));
    let accessProcessing = $state(false);
    let accessMessage = $state<string | null>(null);
    let accessError = $state<string | null>(null);
    let credential = $state<TemporaryCredential | null>(null);
    let credentialOpen = $state(false);
    let copied = $state(false);
    let photoEditorOpen = $state(false);

    function formatDate(value: string | null): string {
        if (!value) return '—';
        return new Date(`${value}T00:00:00`).toLocaleDateString('es-PE', {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        });
    }

    function lastLogin(value: string | null): string {
        if (!value) return 'Nunca';
        return new Date(value).toLocaleString('es-PE', {
            dateStyle: 'medium',
            timeStyle: 'short',
        });
    }

    async function manageAccess(operation: AccessOperation): Promise<void> {
        if (accessProcessing) return;
        accessProcessing = true;
        accessMessage = null;
        accessError = null;

        try {
            const csrfToken =
                document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
            const response = await fetch(`/students/${student.code}/access`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({ operation }),
            });
            const payload = (await response.json()) as AccessResponse;

            if (!response.ok) {
                accessError =
                    Object.values(payload.errors ?? {})[0]?.[0] ??
                    'No se pudo actualizar el acceso.';
                return;
            }

            accessMessage = payload.message;
            if (operation === 'disable') {
                accessState = accessState ? { ...accessState, is_active: false } : null;
                credential = null;
                return;
            }

            accessState = {
                login: payload.credential?.login ?? student.dni,
                is_active: true,
                last_login_at: accessState?.last_login_at ?? null,
            };
            credential = payload.credential;
            copied = false;
            credentialOpen = credential !== null;
        } catch {
            accessError = 'No se pudo actualizar el acceso. Inténtalo nuevamente.';
        } finally {
            accessProcessing = false;
        }
    }

    async function copyCredential(): Promise<void> {
        if (!credential) return;

        try {
            await navigator.clipboard.writeText(
                `Usuario: ${credential.login}\nContraseña temporal: ${credential.temporary_password}`,
            );
            copied = true;
        } catch {
            copied = false;
            accessError = 'El navegador no permitió copiar. Selecciona los datos manualmente.';
        }
    }

    function closeCredential(): void {
        credentialOpen = false;
        credential = null;
        copied = false;
    }
</script>

<svelte:head>
    <title>{fullName} · Alumnos · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title={is_self ? 'Mi perfil' : 'Perfil del alumno'}
        subtitle="Información institucional y académica"
        icon="graduationCap"
        size="xl"
    >
        {#snippet actions()}
            {#if !is_self}
                <Dropdown placement="bottom-end" aria-label="Gestionar alumno">
                    {#snippet triggerContent()}
                        <Button
                            type="button"
                            variant="border"
                            icon="moreVertical"
                            aria-label="Abrir acciones del alumno"
                        />
                    {/snippet}
                    {#snippet content()}
                        {#if can_manage}
                            <DropdownItem
                                icon="edit"
                                onclick={() => router.visit(`/students/${student.code}/edit`)}
                            >
                                Editar datos
                            </DropdownItem>
                        {/if}
                        {#if can_manage_enrollments}
                            <DropdownItem
                                icon={editableEnrollment ? 'edit' : 'plus'}
                                onclick={() =>
                                    router.visit(
                                        editableEnrollment
                                            ? `/enrollments/${editableEnrollment.code}/edit`
                                            : `/students/${student.code}/enrollments/create`,
                                    )}
                            >
                                {editableEnrollment ? 'Editar matrícula' : 'Nueva matrícula'}
                            </DropdownItem>
                        {/if}
                        <DropdownItem
                            icon="search"
                            onclick={() => router.visit('/students/search')}
                        >
                            Volver al directorio
                        </DropdownItem>
                    {/snippet}
                </Dropdown>
            {/if}
        {/snippet}
    </PageHeader>

    <div class="lumi-layout--two-columns">
        <aside class="lumi-layout--sidebar-left lumi-stack">
            <Card image="/images/student-profile-cover.svg" imageAlt="" imageHeight={104} spaced>
                <div class="lumi-stack lumi-stack--md">
                    <UserInfo
                        name={student.first_name}
                        lastName={student.last_name}
                        description={`DNI ${student.dni}`}
                        photoUrl={student.photo_url ?? undefined}
                        avatarSize="xl"
                        avatarColor="primary"
                    />

                    <div class="lumi-flex lumi-flex--wrap lumi-flex--gap-xs">
                        <Chip color={student.is_active ? 'success' : 'secondary'} size="sm">
                            {student.is_active ? 'Alumno activo' : 'Alumno inactivo'}
                        </Chip>
                    </div>

                    {#if can_manage}
                        <Button
                            type="button"
                            variant="border"
                            size="sm"
                            icon="image"
                            onclick={() => (photoEditorOpen = true)}
                        >
                            {student.photo_url ? 'Cambiar foto' : 'Agregar foto'}
                        </Button>
                    {/if}

                    <Divider spaced={false} />

                    <div class="lumi-grid lumi-grid--columns-1 lumi-grid--gap-sm">
                        <InfoItem label="DNI" value={student.dni} icon="creditCard" />
                        <InfoItem
                            label="Nacimiento"
                            value={formatDate(student.birth_date)}
                            icon="calendar"
                        />
                        <InfoItem
                            label="Teléfono"
                            value={student.phone ?? 'Sin teléfono'}
                            icon="phone"
                        />
                        <InfoItem
                            label="Dirección"
                            value={student.address ?? 'Sin dirección'}
                            icon="mapPin"
                        />
                    </div>
                </div>

                {#snippet footer()}
                    <div class="lumi-stack lumi-stack--2xs">
                        <span class="lumi-text--xs lumi-font--medium lumi-text--muted">
                            Observaciones
                        </span>
                        <p
                            class="lumi-margin--none lumi-text--sm"
                            class:lumi-text--muted={!student.observation}
                        >
                            {student.observation ?? 'Sin observaciones registradas.'}
                        </p>
                    </div>
                {/snippet}
            </Card>
        </aside>

        <div class="lumi-layout--content-right lumi-stack lumi-stack--lg">
            <Card
                title={currentEnrollment ? 'Matrícula actual' : 'Sin matrícula activa'}
                subtitle={currentEnrollment
                    ? `${currentEnrollment.branch_name} · Código ${currentEnrollment.roll_code}`
                    : 'El alumno aún no tiene una ubicación académica activa.'}
                spaced
            >
                {#if currentEnrollment}
                    <div class="lumi-grid lumi-grid--responsive lumi-grid--gap-md">
                        <InfoItem
                            icon="bookOpen"
                            label="Ciclo"
                            value={currentEnrollment.cycle_name}
                        />
                        <InfoItem
                            icon="graduationCap"
                            label="Grado y sección"
                            value={`${currentEnrollment.degree_label} · ${currentEnrollment.group_name}`}
                        />
                        <InfoItem
                            icon="clock"
                            label="Turnos"
                            value={currentEnrollment.shift_names || '—'}
                        />
                        <InfoItem
                            icon="activity"
                            label="Estado"
                            value={currentEnrollment.status_label}
                        />
                    </div>
                {:else if can_manage_enrollments}
                    <Button
                        type="button"
                        icon="plus"
                        onclick={() => router.visit(`/students/${student.code}/enrollments/create`)}
                    >
                        Registrar matrícula
                    </Button>
                {/if}
            </Card>

            <Tabs bind:value={activeTab} {tabs} aria-label="Secciones del perfil" />

            {#if activeTab === 'summary'}
                <Card
                    title="Acceso al sistema"
                    subtitle={is_self
                        ? 'Tu cuenta usa el mismo ingreso de Aeduca.'
                        : 'La cuenta no modifica el estado del alumno ni su matrícula.'}
                    spaced
                >
                    <div class="lumi-stack lumi-stack--md">
                        {#if accessMessage}
                            <Alert color="success">{accessMessage}</Alert>
                        {/if}
                        {#if accessError}
                            <Alert color="danger">{accessError}</Alert>
                        {/if}

                        <div class="lumi-grid lumi-grid--responsive lumi-grid--gap-md">
                            <InfoItem
                                label="Usuario"
                                value={accessState?.login ?? 'Sin acceso habilitado'}
                                icon="user"
                            />
                            <InfoItem
                                label="Estado"
                                value={accessState?.is_active ? 'Habilitado' : 'Deshabilitado'}
                                icon="key"
                            />
                            <InfoItem
                                label="Último ingreso"
                                value={lastLogin(accessState?.last_login_at ?? null)}
                                icon="clock"
                            />
                        </div>

                        {#if can_manage}
                            <div class="lumi-flex lumi-flex--wrap lumi-flex--gap-sm">
                                {#if !accessState || !accessState.is_active}
                                    <Button
                                        type="button"
                                        icon="key"
                                        loading={accessProcessing}
                                        onclick={() => manageAccess('enable')}
                                    >
                                        Habilitar acceso
                                    </Button>
                                {:else}
                                    <Button
                                        type="button"
                                        variant="border"
                                        icon="refreshCw"
                                        loading={accessProcessing}
                                        onclick={() => manageAccess('reset')}
                                    >
                                        Restablecer clave
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="flat"
                                        color="danger"
                                        icon="lock"
                                        loading={accessProcessing}
                                        onclick={() => manageAccess('disable')}
                                    >
                                        Deshabilitar acceso
                                    </Button>
                                {/if}
                            </div>
                        {/if}
                    </div>
                </Card>
            {:else if activeTab === 'contacts'}
                <StudentContactsPanel
                    studentCode={student.code}
                    {contacts}
                    canManage={can_manage}
                />
            {:else}
                <StudentEnrollmentsPanel
                    studentCode={student.code}
                    {enrollments}
                    enrollmentCount={enrollment_count}
                    canManage={can_manage_enrollments}
                    isSelf={is_self}
                />
            {/if}
        </div>
    </div>
</div>

<StudentPhotoCropper
    bind:open={photoEditorOpen}
    studentCode={student.code}
    studentName={fullName}
/>

<Dialog
    open={credentialOpen}
    title="Acceso temporal"
    size="sm"
    persistent
    hideClose
    closeOnEscape={false}
>
    <div class="lumi-stack lumi-stack--md">
        <Alert color="warning" title="Se muestra una sola vez">
            Copia estos datos antes de cerrar. La contraseña no podrá recuperarse.
        </Alert>
        <Card spaced>
            <div class="lumi-stack lumi-stack--sm">
                <InfoItem label="Usuario" value={credential?.login ?? ''} icon="user" />
                <InfoItem
                    label="Contraseña temporal"
                    value={credential?.temporary_password ?? ''}
                    icon="key"
                />
            </div>
        </Card>
        <div class="lumi-flex lumi-justify--end lumi-flex--gap-sm">
            <Button type="button" variant="border" icon="copy" onclick={copyCredential}>
                {copied ? 'Copiado' : 'Copiar'}
            </Button>
            <Button type="button" icon="check" onclick={closeCredential}>Ya los guardé</Button>
        </div>
    </div>
</Dialog>
