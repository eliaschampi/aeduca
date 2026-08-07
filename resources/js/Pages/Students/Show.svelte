<script lang="ts">
    import { untrack } from 'svelte';
    import { page, router } from '@inertiajs/svelte';
    import {
        Alert,
        Button,
        Card,
        Chip,
        DashboardSection,
        Dialog,
        Divider,
        Dropdown,
        DropdownItem,
        InfoItem,
        PageHeader,
        Tabs,
        UserInfo,
    } from '@lumi-ui/svelte';
    import StudentContactsPanel from './panels/StudentContactsPanel.svelte';
    import StudentEnrollmentsPanel from './panels/StudentEnrollmentsPanel.svelte';
    import BranchCover from '@/components/BranchCover.svelte';
    import ProfilePhotoCropper from '@/components/ProfilePhotoCropper.svelte';
    import { generateStudentCardPdf } from '@/lib/student-card-pdf';
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
        can_delete?: boolean;
        can_manage_enrollments?: boolean;
        can_delete_enrollments?: boolean;
        can_view_attendance?: boolean;
        can_view_attentions?: boolean;
    }

    const {
        student,
        access,
        contacts,
        enrollments,
        enrollment_count,
        is_self = false,
        can_manage = false,
        can_delete = false,
        can_manage_enrollments = false,
        can_delete_enrollments = false,
        can_view_attendance = false,
        can_view_attentions = false,
    }: Props = $props();

    const fullName = $derived(`${student.first_name} ${student.last_name}`.trim());
    const currentEnrollment = $derived(
        enrollments.find((enrollment) => enrollment.status === 'active') ?? null,
    );
    const canGenerateCard = $derived(
        !is_self && student.is_active && student.photo_url !== null && currentEnrollment !== null,
    );
    const currentBranchEnrollment = $derived(
        enrollments.find(
            (enrollment) => enrollment.is_current_branch && enrollment.status !== 'finalized',
        ) ?? null,
    );
    const hasUnfinishedEnrollment = $derived(
        enrollments.some((enrollment) => enrollment.status !== 'finalized'),
    );
    const profileBranch = $derived(
        page.props.auth?.actor === 'employee' ? page.props.auth.current_branch : null,
    );
    const profileCoverLabel = $derived(
        profileBranch?.name ?? currentEnrollment?.branch_name ?? 'Perfil del alumno',
    );
    const tabs = $derived([
        { value: 'summary', label: 'Acceso', icon: 'key' },
        ...(!is_self ? [{ value: 'contacts', label: 'Contactos', icon: 'users' }] : []),
        { value: 'enrollments', label: 'Matrículas', icon: 'bookOpen' },
    ]);

    let activeTab = $state<ProfileTab>('summary');
    let accessState = $state<StudentAccess | null>(untrack(() => (access ? { ...access } : null)));
    let accessOperation = $state<AccessOperation | null>(null);
    let accessMessage = $state<string | null>(null);
    let accessError = $state<string | null>(null);
    let credential = $state<TemporaryCredential | null>(null);
    let credentialOpen = $state(false);
    let copied = $state(false);
    let photoEditorOpen = $state(false);
    let deleteOpen = $state(false);
    let deleteProcessing = $state(false);
    let deleteError = $state<string | null>(null);
    let cardGenerating = $state(false);
    let cardError = $state<string | null>(null);

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
        if (accessOperation) return;
        accessOperation = operation;
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
            accessOperation = null;
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

    function confirmDelete(): void {
        deleteError = null;
        deleteOpen = true;
    }

    function closeDelete(): void {
        if (deleteProcessing) return;

        deleteOpen = false;
        deleteError = null;
    }

    function removeStudent(): void {
        if (deleteProcessing) return;

        router.delete(`/students/${student.code}`, {
            onStart: () => {
                deleteProcessing = true;
                deleteError = null;
            },
            onError: (errors) => {
                deleteError = errors.student ?? 'No se pudo eliminar el alumno.';
            },
            onFinish: () => {
                deleteProcessing = false;
            },
        });
    }

    async function generateCard(): Promise<void> {
        if (!canGenerateCard || !currentEnrollment || cardGenerating) return;

        cardGenerating = true;
        cardError = null;

        try {
            await generateStudentCardPdf({
                ...student,
                enrollment: currentEnrollment,
            });
        } catch (error) {
            cardError =
                error instanceof Error ? error.message : 'No se pudo generar el carnet del alumno.';
        } finally {
            cardGenerating = false;
        }
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
            {#if is_self && can_view_attendance}
                <Button
                    type="button"
                    variant="border"
                    icon="clipboardCheck"
                    onclick={() => router.visit(`/students/${student.code}/attendance`)}
                >
                    Mi asistencia
                </Button>
            {/if}
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
                        {#if canGenerateCard}
                            <DropdownItem
                                icon="creditCard"
                                color="success"
                                disabled={cardGenerating}
                                onclick={() => void generateCard()}
                            >
                                {cardGenerating ? 'Generando carnet…' : 'Generar carnet'}
                            </DropdownItem>
                        {/if}
                        {#if can_view_attendance}
                            <DropdownItem
                                icon="clipboardCheck"
                                onclick={() => router.visit(`/students/${student.code}/attendance`)}
                            >
                                Historial de asistencia
                            </DropdownItem>
                        {/if}
                        {#if can_view_attentions}
                            <DropdownItem
                                icon="clipboardPenLine"
                                onclick={() => router.visit(`/students/${student.code}/attentions`)}
                            >
                                Atenciones
                            </DropdownItem>
                        {/if}
                        {#if can_manage}
                            <DropdownItem
                                icon="edit"
                                onclick={() => router.visit(`/students/${student.code}/edit`)}
                            >
                                Editar datos
                            </DropdownItem>
                        {/if}
                        {#if can_delete}
                            <DropdownItem icon="trash" color="danger" onclick={confirmDelete}>
                                Eliminar alumno
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

    {#if cardError}
        <Alert color="danger">{cardError}</Alert>
    {/if}

    <div class="lumi-layout--two-columns">
        <aside class="lumi-layout--sidebar-left lumi-stack">
            <Card imageHeight={104} spaced>
                {#snippet media()}
                    <BranchCover
                        label={profileCoverLabel}
                        seed={profileBranch?.code ?? profileCoverLabel}
                    />
                {/snippet}

                <div class="lumi-stack lumi-stack--md">
                    <UserInfo
                        name={student.first_name}
                        lastName={student.last_name}
                        description={`Estado: ${student.is_active ? 'Alumno activo' : 'Alumno inactivo'}`}
                        photoUrl={student.photo_url ?? undefined}
                        avatarSize="xl"
                        avatarColor="primary"
                    />

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
                {:else if can_manage_enrollments && (currentBranchEnrollment || !hasUnfinishedEnrollment)}
                    <Button
                        type="button"
                        icon={currentBranchEnrollment ? 'edit' : 'plus'}
                        onclick={() =>
                            router.visit(
                                currentBranchEnrollment
                                    ? `/enrollments/${currentBranchEnrollment.code}/edit`
                                    : `/students/${student.code}/enrollments/create`,
                            )}
                    >
                        {currentBranchEnrollment ? 'Editar matrícula' : 'Registrar matrícula'}
                    </Button>
                {/if}
            </Card>

            <Tabs bind:value={activeTab} {tabs} aria-label="Secciones del perfil" />

            {#if activeTab === 'summary'}
                <DashboardSection
                    title="Acceso al sistema"
                    subtitle={is_self
                        ? 'Tu cuenta usa el mismo ingreso de Aeduca.'
                        : 'La cuenta no modifica el estado del alumno ni su matrícula.'}
                    spaced
                >
                    {#snippet actions()}
                        {#if can_manage}
                            <div class="lumi-flex lumi-flex--wrap lumi-flex--gap-sm">
                                {#if !accessState || !accessState.is_active}
                                    <Button
                                        type="button"
                                        size="sm"
                                        icon="key"
                                        loading={accessOperation === 'enable'}
                                        onclick={() => manageAccess('enable')}
                                    >
                                        Habilitar acceso
                                    </Button>
                                {:else}
                                    <Dropdown
                                        placement="bottom-start"
                                        disabled={accessOperation !== null}
                                        aria-label="Gestionar acceso del alumno"
                                    >
                                        {#snippet triggerContent()}
                                            <Button
                                                type="button"
                                                size="sm"
                                                icon="key"
                                                loading={accessOperation !== null}
                                            >
                                                Gestionar acceso
                                            </Button>
                                        {/snippet}
                                        {#snippet content()}
                                            <DropdownItem
                                                icon="refreshCw"
                                                onclick={() => manageAccess('reset')}
                                            >
                                                Restablecer clave
                                            </DropdownItem>
                                            <DropdownItem
                                                icon="lock"
                                                color="danger"
                                                onclick={() => manageAccess('disable')}
                                            >
                                                Deshabilitar acceso
                                            </DropdownItem>
                                        {/snippet}
                                    </Dropdown>
                                {/if}
                            </div>
                        {/if}
                    {/snippet}
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
                    </div>
                </DashboardSection>
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
                    canDelete={can_delete_enrollments}
                    isSelf={is_self}
                />
            {/if}
        </div>
    </div>
</div>

<ProfilePhotoCropper
    bind:open={photoEditorOpen}
    uploadUrl={`/students/${student.code}/photo`}
    subjectName={fullName}
    fileName="foto-alumno.webp"
/>

<Dialog
    open={deleteOpen}
    title="Eliminar alumno"
    size="sm"
    persistent={deleteProcessing}
    onclose={closeDelete}
>
    <div class="lumi-stack lumi-stack--md">
        {#if deleteError}
            <Alert color="danger">{deleteError}</Alert>
        {/if}
        <p class="lumi-margin--none">
            Se eliminará definitivamente a <strong>{fullName}</strong>, sus contactos y su acceso al
            sistema. Sólo es posible si no tiene historial académico ni atenciones registradas.
        </p>
        <div class="lumi-flex lumi-justify--end lumi-flex--gap-sm">
            <Button
                type="button"
                variant="border"
                disabled={deleteProcessing}
                onclick={closeDelete}
            >
                Cancelar
            </Button>
            <Button
                type="button"
                color="danger"
                icon="trash"
                loading={deleteProcessing}
                onclick={removeStudent}
            >
                Eliminar
            </Button>
        </div>
    </div>
</Dialog>

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
