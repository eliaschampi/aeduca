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
        RemoteSelect,
        Select,
        Textarea,
        type SelectOption,
        type SelectValue,
    } from '@lumi-ui/svelte';
    import AttentionAttachmentField from './components/AttentionAttachmentField.svelte';
    import type {
        StudentAttentionAttachment,
        StudentAttentionBranch,
        StudentAttentionStudent,
        StudentAttentionTypeOption,
    } from '@/types/student-attention';

    interface EditableAttention {
        code: string;
        student_code: string;
        type: string;
        reason: string;
        development: string;
        conclusion: string;
        occurred_at_local: string;
        attachment: StudentAttentionAttachment | null;
    }

    interface Props {
        branch: StudentAttentionBranch;
        attention: EditableAttention | null;
        selected_student: StudentAttentionStudent | null;
        type_options: StudentAttentionTypeOption[];
        business_timezone: string;
        return_month: string;
        can_use_drive?: boolean;
    }

    const {
        branch,
        attention,
        selected_student,
        type_options,
        business_timezone,
        return_month,
        can_use_drive = false,
    }: Props = $props();
    const isCreate = $derived(attention === null);
    const typeOptions = $derived<SelectOption[]>(type_options);
    const backUrl = $derived(`/student-attentions?month=${encodeURIComponent(return_month)}`);

    function localNow(): string {
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: business_timezone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        }).formatToParts(new Date());
        const value = Object.fromEntries(parts.map((part) => [part.type, part.value]));

        return `${value.year}-${value.month}-${value.day}T${value.hour}:${value.minute}`;
    }

    function seedForm() {
        return {
            student_code: attention?.student_code ?? selected_student?.code ?? '',
            type: attention?.type ?? 'attention',
            reason: attention?.reason ?? '',
            development: attention?.development ?? '',
            conclusion: attention?.conclusion ?? '',
            occurred_at: attention?.occurred_at_local ?? localNow(),
            attachment: attention?.attachment ?? null,
        };
    }

    let form = $state(untrack(seedForm));
    let selectedStudent = $state<StudentAttentionStudent | null>(untrack(() => selected_student));
    let errors = $state<Record<string, string>>({});
    let processing = $state(false);

    function studentLabel(student: StudentAttentionStudent): string {
        return `${student.full_name} · DNI ${student.dni}`;
    }

    function changeStudent(student: StudentAttentionStudent | null): void {
        form.student_code = student?.code ?? '';
    }

    function changeType(value: SelectValue | null): void {
        if (typeof value === 'string') form.type = value;
    }

    function submit(): void {
        if (processing) return;

        const payload = {
            student_code: form.student_code,
            type: form.type,
            reason: form.reason,
            development: form.development,
            conclusion: form.conclusion,
            occurred_at: form.occurred_at,
            drive_file_code: form.attachment?.code ?? null,
        };
        const options = {
            preserveScroll: true,
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

        if (attention) {
            router.put(`/student-attentions/${attention.code}`, payload, options);
            return;
        }

        router.post('/student-attentions', payload, options);
    }
</script>

<svelte:head>
    <title>{isCreate ? 'Nueva atención' : 'Editar atención'} · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title={isCreate ? 'Nueva atención' : 'Editar atención'}
        subtitle={`Registro de atención estudiantil · ${branch.name}`}
        icon="clipboardPenLine"
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
        onsubmit={(event) => {
            event.preventDefault();
            submit();
        }}
    >
        <Card
            title="Registro de atención"
            subtitle="Documenta el motivo, lo ocurrido y los acuerdos en una sola ficha."
            spaced
        >
            <div class="lumi-stack lumi-stack--lg">
                <Fieldset legend="Alumno y contexto">
                    <RemoteSelect
                        bind:value={form.student_code}
                        bind:selected={selectedStudent}
                        endpoint="/student-attentions/students"
                        label="Alumno"
                        placeholder="Busca por nombre o DNI"
                        disabled={!isCreate || processing}
                        clearable={isCreate}
                        minQueryLength={2}
                        debounceMs={220}
                        limit={10}
                        noResultsText="Sin alumnos encontrados en esta sede"
                        errorMessageFallback="No se pudo buscar alumnos."
                        getOptionValue={(student: StudentAttentionStudent) => student.code}
                        getOptionLabel={studentLabel}
                        onchange={changeStudent}
                    />
                    {#if errors.student_code}
                        <Alert color="danger">{errors.student_code}</Alert>
                    {/if}

                    <div class="lumi-grid lumi-grid--columns-2-lg lumi-grid--gap-md">
                        <Select
                            value={form.type}
                            options={typeOptions}
                            label="Tipo"
                            clearable={false}
                            disabled={processing}
                            error={Boolean(errors.type)}
                            errorMessage={errors.type}
                            onchange={changeType}
                        />
                        <Input
                            bind:value={form.occurred_at}
                            type="datetime-local"
                            label="Fecha y hora"
                            max={localNow()}
                            required
                            disabled={processing}
                            danger={Boolean(errors.occurred_at)}
                            dangerText={errors.occurred_at}
                        />
                    </div>

                    <Input
                        bind:value={form.reason}
                        label="Motivo"
                        placeholder="Resume la razón principal de la atención"
                        minlength={5}
                        maxlength={100}
                        required
                        disabled={processing}
                        danger={Boolean(errors.reason)}
                        dangerText={errors.reason}
                    />
                </Fieldset>

                <Fieldset legend="Detalle y acuerdos">
                    <Textarea
                        bind:value={form.development}
                        label="Desarrollo"
                        placeholder="Describe los hechos, intervenciones y aspectos relevantes"
                        rows={8}
                        maxlength={1500}
                        showCount
                        disabled={processing}
                        error={errors.development}
                    />
                    <Textarea
                        bind:value={form.conclusion}
                        label="Conclusión y acuerdos"
                        placeholder="Registra el resultado, los compromisos y el seguimiento acordado"
                        rows={5}
                        maxlength={500}
                        showCount
                        disabled={processing}
                        error={errors.conclusion}
                    />
                </Fieldset>

                <Fieldset legend="Adjunto opcional">
                    <p class="lumi-margin--none lumi-text--sm lumi-text--muted">
                        Elige un archivo de tu Drive o sube uno nuevo. La atención conserva un solo
                        adjunto.
                    </p>
                    <AttentionAttachmentField
                        bind:attachment={form.attachment}
                        canUseDrive={can_use_drive}
                        disabled={processing}
                        error={errors.drive_file_code}
                    />
                </Fieldset>

                <div class="lumi-flex lumi-flex--wrap lumi-justify--end lumi-flex--gap-sm">
                    <Button
                        type="button"
                        variant="border"
                        disabled={processing}
                        onclick={() => router.visit(backUrl)}
                    >
                        Cancelar
                    </Button>
                    <Button type="submit" icon="check" loading={processing}>
                        {isCreate ? 'Registrar atención' : 'Guardar cambios'}
                    </Button>
                </div>
            </div>
        </Card>
    </form>
</div>
