<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { Button, PageHeader } from '@lumi-ui/svelte';
    import type { StudentData } from '@/types/student';
    import StudentForm from './StudentForm.svelte';

    interface Props {
        student: StudentData;
    }

    const { student }: Props = $props();
    const fullName = $derived(`${student.first_name} ${student.last_name}`.trim());
</script>

<svelte:head>
    <title>Editar {fullName} · Estudiantes · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader title="Editar estudiante" subtitle={fullName} icon="edit" size="xl">
        {#snippet actions()}
            <Button
                type="button"
                variant="border"
                icon="arrowLeft"
                onclick={() => router.visit(`/students/${student.code}`)}
            >
                Volver
            </Button>
        {/snippet}
    </PageHeader>

    <StudentForm {student} />
</div>
