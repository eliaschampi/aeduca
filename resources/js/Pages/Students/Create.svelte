<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { Button, PageHeader } from '@lumi-ui/svelte';
    import StudentForm from './StudentForm.svelte';
</script>

<svelte:head>
    <title>Nuevo estudiante · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title="Nuevo estudiante"
        subtitle="Registra la identidad y los datos generales del estudiante."
        icon="userPlus"
        size="xl"
    >
        {#snippet actions()}
            <Button
                type="button"
                variant="border"
                icon="arrowLeft"
                onclick={() => router.visit('/students')}
            >
                Volver
            </Button>
        {/snippet}
    </PageHeader>

    <StudentForm />
</div>
