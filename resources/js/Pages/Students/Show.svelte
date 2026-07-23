<script lang="ts">
    import { untrack } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import { Button, Card, Divider, InfoItem, PageHeader, Tabs, UserInfo } from '@lumi-ui/svelte';
    import { can } from '@/lib/permissions';
    import type { StudentProfileData } from '@/types/student';
    import StudentContactsPanel from './panels/StudentContactsPanel.svelte';
    import StudentEnrollmentsPanel from './panels/StudentEnrollmentsPanel.svelte';

    type ProfileTab = 'enrollments' | 'contacts';

    interface Props {
        student: StudentProfileData;
        can_manage: boolean;
        can_view_enrollments: boolean;
        can_manage_enrollments: boolean;
    }

    const { student, can_manage, can_view_enrollments, can_manage_enrollments }: Props = $props();

    const canManageStudent = $derived(can_manage && can('students.manage'));
    const canViewEnrollments = $derived(can_view_enrollments && can('enrollments.view'));
    const canManageEnrollments = $derived(can_manage_enrollments && can('enrollments.manage'));
    const fullName = $derived(`${student.first_name} ${student.last_name}`.trim());
    const tabs = $derived([
        ...(canViewEnrollments
            ? [{ value: 'enrollments', label: 'Matrículas', icon: 'graduationCap' }]
            : []),
        { value: 'contacts', label: 'Contactos', icon: 'users' },
    ]);

    let activeTab = $state<ProfileTab>(
        untrack(() => (canViewEnrollments ? 'enrollments' : 'contacts')),
    );
    const dateFormatter = new Intl.DateTimeFormat('es-PE', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });

    function formatBirthDate(value: string | null): string {
        if (!value) return 'No registrada';

        const [year, month, day] = value.split('-').map(Number);
        return dateFormatter.format(new Date(year, month - 1, day));
    }
</script>

<svelte:head>
    <title>{fullName} · Estudiantes · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader title={fullName} subtitle={`DNI ${student.dni}`} icon="graduationCap" size="xl">
        {#snippet actions()}
            <Button
                type="button"
                variant="border"
                icon="arrowLeft"
                onclick={() => router.visit('/students')}
            >
                Estudiantes
            </Button>
            {#if canManageStudent}
                <Button
                    type="button"
                    icon="edit"
                    onclick={() => router.visit(`/students/${student.code}/edit`)}
                >
                    Editar
                </Button>
            {/if}
        {/snippet}
    </PageHeader>

    <div class="lumi-layout--two-columns">
        <aside class="lumi-layout--sidebar-left lumi-stack">
            <Card spaced>
                <div class="lumi-stack lumi-stack--md">
                    <UserInfo
                        name={student.first_name}
                        lastName={student.last_name}
                        description="Estudiante"
                        avatarSize="xl"
                        avatarColor="primary"
                    />

                    <Divider spaced={false} />

                    <div class="lumi-grid lumi-grid--columns-1 lumi-grid--gap-sm">
                        <InfoItem icon="creditCard" label="DNI" value={student.dni} />
                        <InfoItem
                            icon="phone"
                            label="Teléfono"
                            value={student.phone ?? 'Sin teléfono'}
                        />
                        <InfoItem
                            icon="calendar"
                            label="Nacimiento"
                            value={formatBirthDate(student.birth_date)}
                        />
                        <InfoItem
                            icon="mapPin"
                            label="Dirección"
                            value={student.address ?? 'Sin dirección'}
                        />
                    </div>
                </div>

                {#if student.observation}
                    {#snippet footer()}
                        <div class="lumi-stack lumi-stack--2xs">
                            <span class="lumi-text--xs lumi-font--medium lumi-text--muted">
                                Observaciones
                            </span>
                            <span class="lumi-text--sm">{student.observation}</span>
                        </div>
                    {/snippet}
                {/if}
            </Card>
        </aside>

        <section class="lumi-layout--content-right lumi-min-width--0">
            <Card spaced>
                <Tabs bind:value={activeTab} {tabs} aria-label="Ficha del estudiante">
                    {#if activeTab === 'enrollments' && canViewEnrollments}
                        <StudentEnrollmentsPanel
                            studentCode={student.code}
                            enrollments={student.enrollments}
                            canManage={canManageEnrollments}
                        />
                    {:else}
                        <StudentContactsPanel
                            studentCode={student.code}
                            contacts={student.contacts}
                            canManage={canManageStudent}
                        />
                    {/if}
                </Tabs>
            </Card>
        </section>
    </div>
</div>
