<script lang="ts">
    import { untrack } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import {
        Alert,
        Button,
        Card,
        Checkbox,
        Chip,
        InfoItem,
        PageHeader,
        Select,
        Switch,
        Textarea,
        type SelectOption,
    } from '@lumi-ui/svelte';

    interface StudentSummary {
        code: string;
        dni: string;
        first_name: string;
        last_name: string;
    }

    interface EnrollmentPayload {
        code: string;
        academic_group_code: string;
        shift_codes: string[];
        roll_code: string;
        is_active: boolean;
        observation: string | null;
    }

    interface GroupOption {
        code: string;
        label: string;
        cycle_code: string;
    }

    interface ShiftOption {
        code: string;
        label: string;
        cycle_code: string;
    }

    interface Props {
        student: StudentSummary;
        enrollment?: EnrollmentPayload | null;
        options: {
            groups: GroupOption[];
            shifts: ShiftOption[];
        };
        can_view_profile?: boolean;
    }

    const { student, enrollment = null, options, can_view_profile = false }: Props = $props();
    const isCreate = $derived(enrollment === null);
    const fullName = $derived(`${student.first_name} ${student.last_name}`.trim());
    const returnUrl = $derived(can_view_profile ? `/students/${student.code}` : '/students');

    function seedForm() {
        return {
            academic_group_code: enrollment?.academic_group_code ?? '',
            shift_codes: [...(enrollment?.shift_codes ?? [])],
            is_active: enrollment?.is_active ?? true,
            observation: enrollment?.observation ?? '',
        };
    }

    let form = $state(untrack(seedForm));
    let processing = $state(false);
    let errors = $state<Record<string, string>>({});

    const selectedGroup = $derived(
        options.groups.find((group) => group.code === form.academic_group_code) ?? null,
    );
    const availableShifts = $derived(
        selectedGroup
            ? options.shifts.filter((shift) => shift.cycle_code === selectedGroup.cycle_code)
            : [],
    );
    const groupOptions = $derived<SelectOption[]>(
        options.groups.map((group) => ({ value: group.code, label: group.label })),
    );

    function changeGroup(value: unknown): void {
        form.academic_group_code = typeof value === 'string' ? value : '';
        form.shift_codes = [];
    }

    function toggleShift(code: string, checked: boolean): void {
        form.shift_codes = checked
            ? [...new Set([...form.shift_codes, code])]
            : form.shift_codes.filter((item) => item !== code);
    }

    function submit(): void {
        if (processing) return;

        const payload = {
            academic_group_code: form.academic_group_code,
            shift_codes: form.shift_codes,
            observation: form.observation,
        };
        const visitOptions = {
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

        if (enrollment) {
            router.put(
                `/enrollments/${enrollment.code}`,
                { ...payload, is_active: form.is_active },
                visitOptions,
            );
        } else {
            router.post(`/students/${student.code}/enrollments`, payload, visitOptions);
        }
    }
</script>

<svelte:head>
    <title>{isCreate ? 'Nueva matrícula' : 'Editar matrícula'} · {fullName} · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title={isCreate ? 'Nueva matrícula' : 'Editar matrícula'}
        subtitle={`${fullName} · DNI ${student.dni}`}
        icon="bookOpen"
        size="xl"
    >
        {#snippet actions()}
            <Button
                type="button"
                variant="border"
                icon="arrowLeft"
                onclick={() => router.visit(returnUrl)}
            >
                {can_view_profile ? 'Perfil' : 'Matriculados'}
            </Button>
        {/snippet}
    </PageHeader>

    {#if options.groups.length === 0}
        <Alert color="warning" title="Sin secciones disponibles">
            La sede actual necesita al menos un ciclo, sección y turno activos antes de matricular.
        </Alert>
    {/if}
    {#if errors.message || errors.enrollment}
        <Alert color="danger">{errors.message ?? errors.enrollment}</Alert>
    {/if}

    <form
        class="lumi-stack lumi-stack--lg"
        onsubmit={(event) => {
            event.preventDefault();
            submit();
        }}
    >
        <div class="lumi-grid lumi-grid--columns-2-lg lumi-grid--gap-lg">
            <Card
                title="Ubicación académica"
                subtitle="La sección determina ciclo, grado y sede."
                spaced
            >
                <div class="lumi-stack lumi-stack--md">
                    <Select
                        value={form.academic_group_code}
                        options={groupOptions}
                        label="Ciclo, grado y sección"
                        placeholder="Selecciona una sección"
                        autocomplete
                        disabled={processing || options.groups.length === 0}
                        error={!!errors.academic_group_code}
                        errorMessage={errors.academic_group_code}
                        onchange={changeGroup}
                    />

                    <div class="lumi-stack lumi-stack--sm">
                        <span class="lumi-font--medium">Turnos</span>
                        {#if !selectedGroup}
                            <span class="lumi-text--sm lumi-text--muted">
                                Selecciona una sección para ver sus turnos.
                            </span>
                        {:else if availableShifts.length === 0}
                            <Alert color="warning"
                                >El ciclo seleccionado no tiene turnos activos.</Alert
                            >
                        {:else}
                            {#each availableShifts as shift (shift.code)}
                                <Checkbox
                                    checked={form.shift_codes.includes(shift.code)}
                                    label={shift.label}
                                    disabled={processing}
                                    onchange={(checked) => toggleShift(shift.code, checked)}
                                />
                            {/each}
                        {/if}
                        {#if errors.shift_codes}
                            <Alert color="danger">{errors.shift_codes}</Alert>
                        {/if}
                    </div>
                </div>
            </Card>

            <Card title="Código y observación" spaced>
                <div class="lumi-stack lumi-stack--md">
                    {#if enrollment}
                        <InfoItem
                            icon="key"
                            label="Código de matrícula"
                            value={enrollment.roll_code}
                        />
                    {:else}
                        <Alert color="info">
                            El código de cuatro dígitos se reservará automáticamente al guardar.
                        </Alert>
                    {/if}

                    {#if enrollment}
                        <Switch
                            bind:checked={form.is_active}
                            label="Matrícula activa"
                            disabled={processing}
                        />
                    {/if}

                    <Textarea
                        bind:value={form.observation}
                        label="Observación"
                        rows={4}
                        maxlength={2000}
                        showCount
                        disabled={processing}
                        error={errors.observation}
                    />
                </div>
            </Card>
        </div>

        <div class="lumi-flex lumi-justify--end lumi-flex--gap-sm">
            <Button
                type="button"
                variant="border"
                onclick={() => router.visit(returnUrl)}
                disabled={processing}
            >
                Cancelar
            </Button>
            <Button
                type="submit"
                icon="check"
                loading={processing}
                disabled={options.groups.length === 0}
            >
                {isCreate ? 'Registrar matrícula' : 'Guardar cambios'}
            </Button>
        </div>
    </form>
</div>
