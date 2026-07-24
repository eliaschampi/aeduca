<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { Button, Dialog, RemoteSelect } from '@lumi-ui/svelte';

    interface StudentLookupItem {
        code: string;
        full_name: string;
        dni: string;
        roll_code: string | null;
    }

    interface Props {
        open?: boolean;
    }

    let { open = $bindable(false) }: Props = $props();

    let selectedCode = $state('');
    let selected = $state<StudentLookupItem | null>(null);

    function label(student: StudentLookupItem): string {
        const roll = student.roll_code ? ` · Código ${student.roll_code}` : '';
        return `${student.full_name} · DNI ${student.dni}${roll}`;
    }

    function reset(): void {
        selectedCode = '';
        selected = null;
    }

    function choose(student: StudentLookupItem | null): void {
        if (!student) return;

        open = false;
        reset();
        router.visit(`/students/${student.code}`);
    }

    function close(): void {
        open = false;
        reset();
    }
</script>

<Dialog bind:open title="Buscar alumno" size="sm" onclose={reset}>
    <div class="lumi-stack lumi-stack--sm">
        <p class="lumi-margin--none lumi-text--sm lumi-text--muted">
            Busca en toda la institución por nombre, DNI o código de matrícula activo.
        </p>
        <RemoteSelect
            bind:value={selectedCode}
            bind:selected
            endpoint="/students/lookup"
            label="Alumno"
            placeholder="Escribe al menos 2 caracteres"
            minQueryLength={2}
            debounceMs={220}
            limit={10}
            noResultsText="Sin alumnos encontrados"
            errorMessageFallback="No se pudo buscar alumnos."
            getOptionValue={(student: StudentLookupItem) => student.code}
            getOptionLabel={label}
            onchange={choose}
        />
    </div>

    {#snippet footer()}
        <Button type="button" variant="border" onclick={close}>Cerrar</Button>
    {/snippet}
</Dialog>
