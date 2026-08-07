<script lang="ts">
    import { untrack } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import {
        Alert,
        Button,
        Card,
        Input,
        PageHeader,
        Select,
        Textarea,
        type SelectOption,
        type SelectValue,
    } from '@lumi-ui/svelte';
    import type {
        StudentAttentionBranch,
        StudentAttentionSubject,
        StudentAttentionTypeOption,
    } from '@/types/student-attention';

    interface EditableAttention {
        code: string;
        type: string;
        reason: string;
        development: string;
        conclusion: string;
        occurred_at_local: string;
    }

    interface Props {
        student: StudentAttentionSubject;
        branch: StudentAttentionBranch;
        attention: EditableAttention | null;
        type_options: StudentAttentionTypeOption[];
        business_timezone: string;
        can_use_drive?: boolean;
    }

    const {
        student,
        branch,
        attention,
        type_options,
        business_timezone,
        can_use_drive = false,
    }: Props = $props();
    const isCreate = $derived(attention === null);
    const typeOptions = $derived<SelectOption[]>(type_options);

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
            type: attention?.type ?? 'attention',
            reason: attention?.reason ?? '',
            development: attention?.development ?? '',
            conclusion: attention?.conclusion ?? '',
            occurred_at: attention?.occurred_at_local ?? localNow(),
        };
    }

    let form = $state(untrack(seedForm));
    let errors = $state<Record<string, string>>({});
    let processing = $state(false);

    function changeType(value: SelectValue | null): void {
        if (typeof value === 'string') form.type = value;
    }

    function submit(attachAfterSave = false): void {
        if (processing) return;

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
        const suffix = attachAfterSave ? '?attach=1' : '';

        if (attention) {
            router.put(
                `/students/${student.code}/attentions/${attention.code}${suffix}`,
                form,
                options,
            );
        } else {
            router.post(`/students/${student.code}/attentions${suffix}`, form, options);
        }
    }
</script>

<svelte:head>
    <title>{isCreate ? 'Nueva atención' : 'Editar atención'} · {student.full_name} · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title={isCreate ? 'Nueva atención' : 'Editar atención'}
        subtitle={`${student.full_name} · DNI ${student.dni} · ${branch.name}`}
        icon="clipboardPenLine"
        size="xl"
    >
        {#snippet actions()}
            <Button
                type="button"
                variant="border"
                icon="arrowLeft"
                onclick={() =>
                    router.visit(
                        attention
                            ? `/students/${student.code}/attentions/${attention.code}`
                            : `/students/${student.code}/attentions`,
                    )}
            >
                Volver
            </Button>
        {/snippet}
    </PageHeader>

    {#if errors.student || errors.message}
        <Alert color="danger">{errors.student ?? errors.message}</Alert>
    {/if}

    <form
        class="lumi-stack lumi-stack--lg"
        onsubmit={(event) => {
            event.preventDefault();
            submit();
        }}
    >
        <Card
            title="Datos de la atención"
            subtitle="El alumno y la sede se obtienen del contexto actual."
            spaced
        >
            <div class="lumi-stack lumi-stack--md">
                <div class="lumi-grid lumi-grid--columns-2-lg lumi-grid--gap-md">
                    <Select
                        value={form.type}
                        options={typeOptions}
                        label="Tipo"
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
                        disabled={processing}
                        danger={Boolean(errors.occurred_at)}
                        dangerText={errors.occurred_at}
                    />
                </div>
                <Input
                    bind:value={form.reason}
                    label="Motivo"
                    placeholder="Resume la razón principal de la atención"
                    maxlength={100}
                    disabled={processing}
                    danger={Boolean(errors.reason)}
                    dangerText={errors.reason}
                />
            </div>
        </Card>

        <Card
            title="Desarrollo"
            subtitle="Describe los hechos, intervenciones y aspectos relevantes."
            spaced
        >
            <Textarea
                bind:value={form.development}
                label="Detalle de la atención"
                placeholder="Registra el desarrollo de la atención con la extensión necesaria"
                rows={12}
                disabled={processing}
                error={errors.development}
            />
        </Card>

        <Card
            title="Conclusión y acuerdos"
            subtitle="Deja constancia del resultado, compromisos y seguimiento acordado."
            spaced
        >
            <Textarea
                bind:value={form.conclusion}
                label="Resultado de la atención"
                placeholder="Registra los acuerdos o la conclusión alcanzada"
                rows={8}
                disabled={processing}
                error={errors.conclusion}
            />
        </Card>

        <div class="lumi-flex lumi-justify--end lumi-flex--gap-sm">
            <Button
                type="button"
                variant="border"
                disabled={processing}
                onclick={() => router.visit(`/students/${student.code}/attentions`)}
            >
                Cancelar
            </Button>
            {#if can_use_drive}
                <Button
                    type="button"
                    variant="border"
                    icon="link"
                    loading={processing}
                    onclick={() => submit(true)}
                >
                    {isCreate ? 'Registrar y adjuntar' : 'Guardar y adjuntar'}
                </Button>
            {/if}
            <Button type="submit" icon="check" loading={processing}>
                {isCreate ? 'Registrar atención' : 'Guardar cambios'}
            </Button>
        </div>
    </form>
</div>
