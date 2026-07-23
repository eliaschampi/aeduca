<script lang="ts">
    import { untrack } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import {
        Alert,
        Button,
        Card,
        Checkbox,
        Fieldset,
        Input,
        PageHeader,
        Select,
        Switch,
        Tabs,
        Textarea,
        UserInfo,
        type SelectOption,
    } from '@lumi-ui/svelte';

    type EnrollmentTab = 'assignment' | 'obligations';

    interface StudentSummary {
        code: string;
        dni: string;
        full_name: string;
    }

    interface ObligationEntry {
        code?: string | null;
        concept: string;
        amount: string | number;
        due_date: string;
    }

    interface EnrollmentPayload {
        code: string;
        cycle_code: string;
        academic_group_code: string;
        shift_codes: string[];
        is_active: boolean;
        observation: string | null;
        obligations: ObligationEntry[];
    }

    interface CycleOption {
        code: string;
        label: string;
        groups: SelectOption[];
        shifts: SelectOption[];
    }

    interface Props {
        student: StudentSummary;
        enrollment: EnrollmentPayload | null;
        cycles: CycleOption[];
        default_cycle_code: string | null;
        business_date: string;
    }

    const { student, enrollment, cycles, default_cycle_code, business_date }: Props = $props();

    const isCreate = $derived(enrollment === null);
    const cycleOptions = $derived(
        cycles.map((cycle) => ({ value: cycle.code, label: cycle.label })),
    );

    function seedForm() {
        return {
            cycle_code: enrollment?.cycle_code ?? default_cycle_code ?? '',
            academic_group_code: enrollment?.academic_group_code ?? '',
            shift_codes: [...(enrollment?.shift_codes ?? [])],
            is_active: enrollment?.is_active ?? true,
            observation: enrollment?.observation ?? '',
            obligations: (
                enrollment?.obligations ?? [{ concept: '', amount: '', due_date: business_date }]
            ).map((obligation): ObligationEntry => ({ ...obligation })),
        };
    }

    let form = $state(untrack(() => seedForm()));
    let processing = $state(false);
    let errors = $state<Record<string, string>>({});
    let activeTab = $state<EnrollmentTab>('assignment');

    const selectedCycle = $derived(cycles.find((cycle) => cycle.code === form.cycle_code));
    const tabs = $derived([
        {
            value: 'assignment',
            label: hasAssignmentErrors() ? 'Asignación · Revisar' : 'Asignación académica',
            icon: hasAssignmentErrors() ? 'alertTriangle' : 'graduationCap',
        },
        {
            value: 'obligations',
            label: hasObligationErrors() ? 'Obligaciones · Revisar' : 'Obligaciones',
            icon: hasObligationErrors() ? 'alertTriangle' : 'wallet',
        },
    ]);

    function hasAssignmentErrors(): boolean {
        return Object.keys(errors).some(
            (key) =>
                key === 'academic_group_code' ||
                key === 'shift_codes' ||
                key.startsWith('shift_codes.') ||
                key === 'is_active' ||
                key === 'observation',
        );
    }

    function hasObligationErrors(): boolean {
        return Object.keys(errors).some(
            (key) => key === 'obligations' || key.startsWith('obligations.'),
        );
    }

    function onCycleChange(value: string | number | Record<string, unknown> | null): void {
        form.cycle_code = typeof value === 'string' ? value : '';
        form.academic_group_code = '';
        form.shift_codes = [];
    }

    function toggleShift(code: string, checked: boolean): void {
        form.shift_codes = checked
            ? [...new Set([...form.shift_codes, code])]
            : form.shift_codes.filter((current) => current !== code);
    }

    function addObligation(): void {
        form.obligations = [
            ...form.obligations,
            { concept: '', amount: '', due_date: business_date },
        ];
    }

    function removeObligation(index: number): void {
        if (form.obligations.length <= 1) return;
        form.obligations = form.obligations.filter((_, current) => current !== index);
    }

    function submit(): void {
        if (processing || cycles.length === 0) return;

        const payload = {
            academic_group_code: form.academic_group_code,
            shift_codes: form.shift_codes,
            is_active: form.is_active,
            observation: form.observation,
            obligations: form.obligations.map((obligation) => ({
                ...(obligation.code ? { code: obligation.code } : {}),
                concept: obligation.concept,
                amount: obligation.amount,
                due_date: obligation.due_date,
            })),
        };
        const options = {
            onStart: () => {
                processing = true;
                errors = {};
            },
            onError: (formErrors: Record<string, string>) => {
                errors = formErrors;
                activeTab = hasObligationErrors() ? 'obligations' : 'assignment';
            },
            onFinish: () => {
                processing = false;
            },
        };

        if (isCreate) {
            router.post(`/students/${student.code}/enrollments`, payload, options);
        } else if (enrollment) {
            router.put(
                `/students/${student.code}/enrollments/${enrollment.code}`,
                payload,
                options,
            );
        }
    }
</script>

<svelte:head>
    <title>{isCreate ? 'Nueva matrícula' : `Matrícula ${enrollment?.code}`} · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title={isCreate ? 'Nueva matrícula' : 'Editar matrícula'}
        subtitle="Asignación académica y obligaciones iniciales en una sola operación."
        icon="graduationCap"
        size="xl"
    >
        {#snippet actions()}
            <Button
                type="button"
                variant="border"
                icon="arrowLeft"
                onclick={() => router.visit(`/students/${student.code}`)}
            >
                Perfil
            </Button>
        {/snippet}
    </PageHeader>

    <Card spaced>
        <UserInfo
            name={student.full_name}
            description={`DNI ${student.dni}`}
            avatarSize="md"
            avatarColor="primary"
        />
    </Card>

    {#if cycles.length === 0}
        <Alert color="warning">
            No hay ciclos vigentes con secciones y turnos disponibles en tus sedes.
        </Alert>
    {/if}

    {#if errors.message}
        <Alert color="danger">{errors.message}</Alert>
    {/if}

    <Tabs bind:value={activeTab} {tabs} aria-label="Secciones de la matrícula">
        <form
            class="lumi-stack lumi-stack--lg"
            aria-busy={processing}
            onsubmit={(event) => {
                event.preventDefault();
                submit();
            }}
        >
            {#if activeTab === 'assignment'}
                <Card
                    title="Asignación académica"
                    subtitle="Selecciona el ciclo, la sección concreta y uno o ambos turnos."
                    spaced
                >
                    <div class="lumi-stack lumi-stack--md">
                        <div class="lumi-grid lumi-grid--responsive lumi-grid--gap-md">
                            <Select
                                label="Ciclo y sede"
                                value={form.cycle_code}
                                options={cycleOptions}
                                placeholder="Selecciona un ciclo"
                                disabled={cycles.length === 0}
                                onchange={onCycleChange}
                            />
                            <Select
                                label="Grado y sección"
                                bind:value={form.academic_group_code}
                                options={selectedCycle?.groups ?? []}
                                placeholder="Selecciona una sección"
                                disabled={!selectedCycle}
                                error={Boolean(errors.academic_group_code)}
                                errorMessage={errors.academic_group_code}
                            />
                        </div>

                        <Fieldset legend="Turnos">
                            <div class="lumi-stack lumi-stack--sm">
                                {#if selectedCycle}
                                    <div
                                        class="lumi-flex lumi-flex--wrap lumi-flex--gap-lg lumi-align-items--center"
                                    >
                                        {#each selectedCycle.shifts as shift (String(shift.value))}
                                            <Checkbox
                                                label={shift.label}
                                                checked={form.shift_codes.includes(
                                                    String(shift.value),
                                                )}
                                                onchange={(checked) =>
                                                    toggleShift(String(shift.value), checked)}
                                            />
                                        {/each}
                                    </div>
                                {:else}
                                    <p class="lumi-text--sm lumi-text--muted lumi-margin--none">
                                        Selecciona primero un ciclo.
                                    </p>
                                {/if}

                                {#if errors.shift_codes}
                                    <p class="lumi-text--sm lumi-text--danger lumi-margin--none">
                                        {errors.shift_codes}
                                    </p>
                                {/if}
                            </div>
                        </Fieldset>

                        <Switch bind:checked={form.is_active} label="Matrícula activa" />

                        <Textarea
                            label="Observación"
                            placeholder="Información adicional de esta matrícula"
                            bind:value={form.observation}
                            rows={3}
                            error={errors.observation ?? false}
                        />
                    </div>
                </Card>
            {:else}
                <Card
                    title="Obligaciones iniciales"
                    subtitle="Registra los conceptos, importes y vencimientos que nacen con esta matrícula."
                    spaced
                >
                    <div class="lumi-stack lumi-stack--md">
                        {#each form.obligations as obligation, index (index)}
                            <Fieldset legend={`Obligación ${index + 1}`}>
                                <div
                                    class="lumi-grid lumi-grid--columns-3 lumi-grid--gap-sm lumi-align-items--end"
                                >
                                    <Input
                                        label="Concepto"
                                        placeholder="Ej. Matrícula o pensión de marzo"
                                        bind:value={obligation.concept}
                                        maxlength={150}
                                        required
                                        danger={Boolean(errors[`obligations.${index}.concept`])}
                                        dangerText={errors[`obligations.${index}.concept`]}
                                    />
                                    <Input
                                        label="Importe"
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                        bind:value={obligation.amount}
                                        required
                                        danger={Boolean(errors[`obligations.${index}.amount`])}
                                        dangerText={errors[`obligations.${index}.amount`]}
                                    />
                                    <div class="lumi-flex lumi-flex--gap-xs lumi-align-items--end">
                                        <div class="lumi-flex-item--grow">
                                            <Input
                                                label="Vencimiento"
                                                type="date"
                                                bind:value={obligation.due_date}
                                                required
                                                danger={Boolean(
                                                    errors[`obligations.${index}.due_date`],
                                                )}
                                                dangerText={errors[`obligations.${index}.due_date`]}
                                            />
                                        </div>
                                        {#if form.obligations.length > 1}
                                            <Button
                                                type="button"
                                                variant="flat"
                                                color="danger"
                                                icon="trash"
                                                aria-label={`Quitar obligación ${index + 1}`}
                                                onclick={() => removeObligation(index)}
                                            />
                                        {/if}
                                    </div>
                                </div>
                            </Fieldset>
                        {/each}

                        {#if errors.obligations}
                            <p class="lumi-text--sm lumi-text--danger lumi-margin--none">
                                {errors.obligations}
                            </p>
                        {/if}

                        <div>
                            <Button
                                type="button"
                                variant="border"
                                size="sm"
                                icon="plus"
                                onclick={addObligation}
                            >
                                Agregar obligación
                            </Button>
                        </div>
                    </div>
                </Card>
            {/if}

            <div class="lumi-flex lumi-justify--end lumi-flex--gap-sm">
                <Button
                    type="button"
                    variant="border"
                    disabled={processing}
                    onclick={() => router.visit(`/students/${student.code}`)}
                >
                    Cancelar
                </Button>
                <Button
                    type="submit"
                    icon="check"
                    loading={processing}
                    disabled={cycles.length === 0}
                >
                    {isCreate ? 'Registrar matrícula' : 'Guardar matrícula'}
                </Button>
            </div>
        </form>
    </Tabs>
</div>
