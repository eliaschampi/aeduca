<script lang="ts">
    import { untrack } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import { Button, Card, Fieldset, Input, Textarea } from '@lumi-ui/svelte';
    import type { StudentData } from '@/types/student';

    interface StudentFormState {
        first_name: string;
        last_name: string;
        dni: string;
        birth_date: string;
        phone: string;
        address: string;
        observation: string;
    }

    interface Props {
        student?: StudentData | null;
    }

    const { student = null }: Props = $props();
    const isEditing = $derived(student !== null);

    function initialForm(source: StudentData | null): StudentFormState {
        return {
            first_name: source?.first_name ?? '',
            last_name: source?.last_name ?? '',
            dni: source?.dni ?? '',
            birth_date: source?.birth_date ?? '',
            phone: source?.phone ?? '',
            address: source?.address ?? '',
            observation: source?.observation ?? '',
        };
    }

    let form = $state(initialForm(untrack(() => student)));
    let processing = $state(false);
    let errors = $state<Record<string, string>>({});

    function cancel(): void {
        router.visit(isEditing ? `/students/${student.code}` : '/students');
    }

    function submit(): void {
        if (processing) return;

        const options = {
            onStart: () => {
                processing = true;
                errors = {};
            },
            onError: (formErrors: Record<string, string>) => {
                errors = formErrors;
            },
            onFinish: () => {
                processing = false;
            },
        };

        if (isEditing) {
            router.put(`/students/${student.code}`, { ...form }, options);
            return;
        }

        router.post('/students', { ...form }, options);
    }
</script>

<form
    class="lumi-stack lumi-stack--lg"
    aria-busy={processing}
    onsubmit={(event) => {
        event.preventDefault();
        submit();
    }}
>
    <Card
        title="Datos del estudiante"
        subtitle="La identidad es única para toda la institución."
        spaced
    >
        <div class="lumi-stack lumi-stack--md">
            <Fieldset legend="Identidad">
                <div class="lumi-grid lumi-grid--columns-2 lumi-grid--gap-md">
                    <Input
                        label="Nombres"
                        placeholder="Ej. María Elena"
                        bind:value={form.first_name}
                        maxlength={50}
                        required
                        danger={Boolean(errors.first_name)}
                        dangerText={errors.first_name}
                    />
                    <Input
                        label="Apellidos"
                        placeholder="Ej. Quispe Mamani"
                        bind:value={form.last_name}
                        maxlength={80}
                        required
                        danger={Boolean(errors.last_name)}
                        dangerText={errors.last_name}
                    />
                    <Input
                        label="DNI"
                        placeholder="Ej. 71234567"
                        bind:value={form.dni}
                        maxlength={8}
                        required
                        danger={Boolean(errors.dni)}
                        dangerText={errors.dni}
                    />
                    <Input
                        label="Fecha de nacimiento"
                        type="date"
                        bind:value={form.birth_date}
                        danger={Boolean(errors.birth_date)}
                        dangerText={errors.birth_date}
                    />
                </div>
            </Fieldset>

            <Fieldset legend="Contacto y ubicación">
                <div class="lumi-grid lumi-grid--columns-2 lumi-grid--gap-md">
                    <Input
                        label="Teléfono"
                        type="tel"
                        placeholder="Ej. 987 654 321"
                        bind:value={form.phone}
                        maxlength={50}
                        danger={Boolean(errors.phone)}
                        dangerText={errors.phone}
                    />
                    <Input
                        label="Dirección"
                        placeholder="Ej. Av. La Cultura 123"
                        bind:value={form.address}
                        maxlength={150}
                        danger={Boolean(errors.address)}
                        dangerText={errors.address}
                    />
                    <Textarea
                        class="lumi-grid-item--span-all"
                        label="Observaciones"
                        placeholder="Información útil sobre el estudiante"
                        bind:value={form.observation}
                        rows={4}
                        error={errors.observation ?? false}
                    />
                </div>
            </Fieldset>
        </div>
    </Card>

    <div class="lumi-flex lumi-justify--end lumi-flex--gap-sm lumi-flex--wrap">
        <Button type="button" variant="border" disabled={processing} onclick={cancel}>
            Cancelar
        </Button>
        <Button type="submit" icon="check" loading={processing}>
            {isEditing ? 'Guardar cambios' : 'Crear estudiante'}
        </Button>
    </div>
</form>
