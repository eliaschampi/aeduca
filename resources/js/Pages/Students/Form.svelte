<script lang="ts">
    import { untrack } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import {
        Alert,
        Button,
        Card,
        Fieldset,
        Input,
        PageHeader,
        Switch,
        Textarea,
    } from '@lumi-ui/svelte';
    import type { StudentProfile } from '@/types/student';

    interface Props {
        student?: StudentProfile | null;
    }

    const { student = null }: Props = $props();
    const isCreate = $derived(student === null);

    function seedForm() {
        return {
            dni: student?.dni ?? '',
            first_name: student?.first_name ?? '',
            last_name: student?.last_name ?? '',
            birth_date: student?.birth_date ?? '',
            phone: student?.phone ?? '',
            address: student?.address ?? '',
            observation: student?.observation ?? '',
            is_active: student?.is_active ?? true,
        };
    }

    let form = $state(untrack(seedForm));
    let processing = $state(false);
    let errors = $state<Record<string, string>>({});

    const fullName = $derived(`${form.first_name || 'Nuevo'} ${form.last_name || 'alumno'}`.trim());
    const backUrl = $derived(student ? `/students/${student.code}` : '/students/search');

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

        if (student) {
            router.put(`/students/${student.code}`, { ...form }, options);
            return;
        }

        router.post('/students', { ...form }, options);
    }
</script>

<svelte:head>
    <title>{isCreate ? 'Nuevo alumno' : fullName} · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title={isCreate ? 'Nuevo alumno' : 'Editar alumno'}
        subtitle={isCreate
            ? 'Registra primero su identidad. La foto se incorpora después desde el perfil.'
            : 'Actualiza la identidad y los datos de contacto del alumno.'}
        icon={isCreate ? 'userPlus' : 'userPen'}
        size="xl"
    >
        {#snippet actions()}
            <Button
                type="button"
                variant="border"
                icon="arrowLeft"
                onclick={() => router.visit(backUrl)}
            >
                Volver
            </Button>
        {/snippet}
    </PageHeader>

    {#if errors.message}
        <Alert color="danger">{errors.message}</Alert>
    {/if}

    <form
        class="lumi-centered-layout"
        onsubmit={(event) => {
            event.preventDefault();
            submit();
        }}
    >
        <Card
            class="lumi-centered-card lumi-centered-card--xl"
            title="Ficha del alumno"
            subtitle="Los campos obligatorios identifican al alumno en toda la institución."
            spaced
        >
            <div class="lumi-stack lumi-stack--lg">
                <Fieldset legend="Identidad">
                    <div class="lumi-grid lumi-grid--columns-2 lumi-grid--gap-md">
                        <Input
                            bind:value={form.dni}
                            label="DNI"
                            placeholder="Ej. 76543210"
                            maxlength={10}
                            required
                            disabled={processing}
                            danger={Boolean(errors.dni)}
                            dangerText={errors.dni}
                        />
                        <Input
                            bind:value={form.birth_date}
                            label="Fecha de nacimiento"
                            placeholder="Selecciona una fecha"
                            type="date"
                            disabled={processing}
                            danger={Boolean(errors.birth_date)}
                            dangerText={errors.birth_date}
                        />
                    </div>

                    <div class="lumi-grid lumi-grid--columns-2 lumi-grid--gap-md">
                        <Input
                            bind:value={form.first_name}
                            label="Nombres"
                            placeholder="Ej. Valeria Lucía"
                            maxlength={100}
                            required
                            disabled={processing}
                            danger={Boolean(errors.first_name)}
                            dangerText={errors.first_name}
                        />
                        <Input
                            bind:value={form.last_name}
                            label="Apellidos"
                            placeholder="Ej. Ramos Quispe"
                            maxlength={100}
                            required
                            disabled={processing}
                            danger={Boolean(errors.last_name)}
                            dangerText={errors.last_name}
                        />
                    </div>
                </Fieldset>

                <Fieldset legend="Contacto">
                    <div class="lumi-grid lumi-grid--columns-2 lumi-grid--gap-md">
                        <Input
                            bind:value={form.phone}
                            label="Teléfono"
                            placeholder="Ej. 987 654 321"
                            maxlength={30}
                            disabled={processing}
                            danger={Boolean(errors.phone)}
                            dangerText={errors.phone}
                        />
                        <Input
                            bind:value={form.address}
                            label="Dirección"
                            placeholder="Ej. Av. Principal 123, Lima"
                            maxlength={250}
                            disabled={processing}
                            danger={Boolean(errors.address)}
                            dangerText={errors.address}
                        />
                    </div>
                </Fieldset>

                <Fieldset legend="Información adicional">
                    <Textarea
                        bind:value={form.observation}
                        label="Observación"
                        placeholder="Registra sólo información útil para la atención del alumno."
                        rows={3}
                        maxlength={2000}
                        showCount
                        disabled={processing}
                        error={errors.observation}
                    />

                    {#if !isCreate}
                        <Switch
                            bind:checked={form.is_active}
                            label="Alumno activo"
                            disabled={processing}
                        />
                    {/if}
                </Fieldset>

                <div class="lumi-flex lumi-flex--wrap lumi-justify--end lumi-flex--gap-sm">
                    <Button
                        type="button"
                        variant="border"
                        onclick={() => router.visit(backUrl)}
                        disabled={processing}
                    >
                        Cancelar
                    </Button>
                    <Button type="submit" icon="check" loading={processing}>
                        {isCreate ? 'Registrar alumno' : 'Guardar cambios'}
                    </Button>
                </div>
            </div>
        </Card>
    </form>
</div>
