<script lang="ts">
    import { untrack } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import {
        Alert,
        Button,
        Card,
        Chip,
        Fieldset,
        Input,
        PageHeader,
        Select,
        Switch,
        TagOption,
        type SelectOption,
    } from '@lumi-ui/svelte';

    interface GroupEntry {
        code: string | null;
        name: string;
    }

    interface DegreeEntry {
        number: number;
        groups: GroupEntry[];
    }

    interface ShiftEntry {
        code: string | null;
        name: string;
        entry_time: string;
        tolerance_minutes: number;
    }

    interface CyclePayload {
        code: string;
        name: string;
        level: string;
        modality: string;
        start_date: string;
        end_date: string;
        is_active: boolean;
        shifts: { code: string; name: string; entry_time: string; tolerance_minutes: number }[];
        degrees: { number: number; groups: { code: string; name: string }[] }[];
    }

    interface Props {
        cycle?: CyclePayload | null;
        level_options: SelectOption[];
        modality_options: SelectOption[];
        grade_numbers: Record<string, number[]>;
    }

    const { cycle = null, level_options, modality_options, grade_numbers }: Props = $props();

    const isCreate = $derived(cycle === null);

    function seedForm() {
        return {
            name: cycle?.name ?? '',
            level: cycle?.level ?? 'primary',
            modality: cycle?.modality ?? 'regular',
            start_date: cycle?.start_date ?? '',
            end_date: cycle?.end_date ?? '',
            is_active: cycle?.is_active ?? true,
            shifts: (
                cycle?.shifts ?? [
                    { code: null, name: 'Mañana', entry_time: '07:00', tolerance_minutes: 60 },
                ]
            ).map((shift): ShiftEntry => ({ ...shift })) as ShiftEntry[],
            degrees: (cycle?.degrees ?? []).map((degree): DegreeEntry => ({
                number: degree.number,
                groups: degree.groups.map((group): GroupEntry => ({ ...group })),
            })),
        };
    }

    let form = $state(untrack(() => seedForm()));
    let processing = $state(false);
    let errors = $state<Record<string, string>>({});

    const availableGrades = $derived(grade_numbers[form.level] ?? []);
    const selectedNumbers = $derived(new Set(form.degrees.map((degree) => degree.number)));
    const sortedDegrees = $derived([...form.degrees].sort((a, b) => a.number - b.number));

    function gradeLabel(number: number): string {
        return `${number}°`;
    }

    function toggleGrade(number: number): void {
        if (selectedNumbers.has(number)) {
            form.degrees = form.degrees.filter((degree) => degree.number !== number);
        } else {
            form.degrees = [...form.degrees, { number, groups: [{ code: null, name: '' }] }];
        }
    }

    function onLevelChange(value: string | number | Record<string, unknown> | null): void {
        form.level = typeof value === 'string' ? value : 'primary';
        const valid = new Set(grade_numbers[form.level] ?? []);
        form.degrees = form.degrees.filter((degree) => valid.has(degree.number));
    }

    function addShift(): void {
        if (form.shifts.length >= 2) return;
        form.shifts = [
            ...form.shifts,
            { code: null, name: 'Tarde', entry_time: '13:00', tolerance_minutes: 30 },
        ];
    }

    function removeShift(index: number): void {
        if (form.shifts.length <= 1) return;
        form.shifts = form.shifts.filter((_, i) => i !== index);
    }

    function addGroup(degree: DegreeEntry): void {
        degree.groups = [...degree.groups, { code: null, name: '' }];
    }

    function removeGroup(degree: DegreeEntry, index: number): void {
        degree.groups = degree.groups.filter((_, i) => i !== index);
    }

    function submit(): void {
        if (processing) return;

        const payload = {
            name: form.name,
            level: form.level,
            modality: form.modality,
            start_date: form.start_date,
            end_date: form.end_date,
            is_active: form.is_active,
            shifts: form.shifts.map((shift) => ({
                ...(shift.code ? { code: shift.code } : {}),
                name: shift.name,
                entry_time: shift.entry_time,
                tolerance_minutes: shift.tolerance_minutes,
            })),
            degrees: sortedDegrees.map((degree) => ({
                number: degree.number,
                groups: degree.groups
                    .filter((group) => group.name.trim() !== '' || group.code !== null)
                    .map((group) => ({
                        ...(group.code ? { code: group.code } : {}),
                        name: group.name,
                    })),
            })),
        };

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

        if (isCreate) {
            router.post('/admin/cycles', payload, options);
        } else if (cycle) {
            router.put(`/admin/cycles/${cycle.code}`, payload, options);
        }
    }
</script>

<svelte:head>
    <title>{isCreate ? 'Nuevo ciclo' : form.name || 'Ciclo'} · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title={isCreate ? 'Nuevo ciclo' : form.name || 'Ciclo'}
        subtitle="Información general, turnos de asistencia y estructura de grados con secciones."
        icon="bookOpen"
        size="xl"
    >
        {#snippet actions()}
            <Button
                type="button"
                variant="border"
                icon="arrowLeft"
                onclick={() => router.visit('/admin/cycles')}
            >
                Ciclos
            </Button>
        {/snippet}
    </PageHeader>

    <div class="lumi-flex lumi-flex--wrap lumi-flex--gap-sm lumi-align-items--center">
        <Chip color={form.is_active ? 'success' : 'secondary'} size="sm">
            {form.is_active ? 'Activo' : 'Inactivo'}
        </Chip>
        <Chip color="info" size="sm">
            {form.degrees.length}
            {form.degrees.length === 1 ? 'grado' : 'grados'}
        </Chip>
        <Chip color="info" size="sm">
            {form.shifts.length}
            {form.shifts.length === 1 ? 'turno' : 'turnos'}
        </Chip>
    </div>

    {#if errors.message}
        <Alert color="danger">{errors.message}</Alert>
    {/if}

    <form
        class="lumi-stack lumi-stack--lg"
        onsubmit={(event) => {
            event.preventDefault();
            submit();
        }}
    >
        <Card title="General" subtitle="Identidad del ciclo y sus turnos de asistencia." spaced>
            <div class="lumi-stack lumi-stack--md">
                <Input
                    label="Nombre"
                    placeholder="Ej. Primaria 2026"
                    bind:value={form.name}
                    maxlength={120}
                    required
                    danger={!!errors.name}
                    dangerText={errors.name}
                />

                <div class="lumi-grid lumi-grid--responsive lumi-grid--gap-md">
                    <Select
                        label="Nivel"
                        bind:value={form.level}
                        options={level_options}
                        onchange={(value) => onLevelChange(value)}
                        error={!!errors.level}
                        errorMessage={errors.level}
                    />
                    <Select
                        label="Modalidad"
                        bind:value={form.modality}
                        options={modality_options}
                        error={!!errors.modality}
                        errorMessage={errors.modality}
                    />
                </div>

                <div class="lumi-grid lumi-grid--responsive lumi-grid--gap-md">
                    <Input
                        label="Fecha de inicio"
                        type="date"
                        bind:value={form.start_date}
                        required
                        danger={!!errors.start_date}
                        dangerText={errors.start_date}
                    />
                    <Input
                        label="Fecha de fin"
                        type="date"
                        bind:value={form.end_date}
                        required
                        danger={!!errors.end_date}
                        dangerText={errors.end_date}
                    />
                </div>

                <Switch bind:checked={form.is_active} label="Ciclo activo" />

                <Fieldset legend="Turnos de asistencia">
                    <div class="lumi-stack lumi-stack--sm">
                        {#each form.shifts as shift, index (shift.code ?? `new-${index}`)}
                            <div
                                class="lumi-grid lumi-grid--columns-3 lumi-grid--gap-sm lumi-align-items--end"
                            >
                                <Input
                                    label={index === 0 ? 'Turno 1' : 'Turno 2'}
                                    placeholder="Ej. Mañana"
                                    bind:value={shift.name}
                                    maxlength={60}
                                    required
                                    danger={!!errors[`shifts.${index}.name`]}
                                    dangerText={errors[`shifts.${index}.name`]}
                                />
                                <Input
                                    label="Hora de entrada"
                                    type="time"
                                    bind:value={shift.entry_time}
                                    required
                                    danger={!!errors[`shifts.${index}.entry_time`]}
                                    dangerText={errors[`shifts.${index}.entry_time`]}
                                />
                                <div class="lumi-flex lumi-flex--gap-xs lumi-align-items--end">
                                    <div class="lumi-flex-item--grow">
                                        <Input
                                            label="Tolerancia (min)"
                                            type="number"
                                            min={0}
                                            bind:value={shift.tolerance_minutes}
                                            danger={!!errors[`shifts.${index}.tolerance_minutes`]}
                                            dangerText={errors[`shifts.${index}.tolerance_minutes`]}
                                        />
                                    </div>
                                    {#if form.shifts.length > 1}
                                        <Button
                                            type="button"
                                            variant="flat"
                                            color="danger"
                                            icon="trash"
                                            aria-label={`Quitar turno ${index + 1}`}
                                            onclick={() => removeShift(index)}
                                        />
                                    {/if}
                                </div>
                            </div>
                        {/each}

                        {#if form.shifts.length < 2}
                            <div>
                                <Button
                                    type="button"
                                    variant="border"
                                    size="sm"
                                    icon="plus"
                                    onclick={addShift}
                                >
                                    Agregar turno
                                </Button>
                            </div>
                        {/if}

                        {#if errors.shifts}
                            <p class="lumi-text--sm lumi-text--danger lumi-margin--none">
                                {errors.shifts}
                            </p>
                        {/if}
                    </div>
                </Fieldset>
            </div>
        </Card>

        <Card
            title="Estructura académica"
            subtitle="Grados que ofrece el ciclo y las secciones de cada grado."
            spaced
        >
            <div class="lumi-stack lumi-stack--md">
                <Fieldset legend="Grados ofrecidos">
                    <div class="lumi-stack lumi-stack--sm">
                        <div class="lumi-flex lumi-flex--wrap lumi-flex--gap-xs">
                            {#each availableGrades as number (number)}
                                <TagOption
                                    label={gradeLabel(number)}
                                    active={selectedNumbers.has(number)}
                                    onclick={() => toggleGrade(number)}
                                />
                            {/each}
                        </div>

                        {#if errors.degrees}
                            <p class="lumi-text--sm lumi-text--danger lumi-margin--none">
                                {errors.degrees}
                            </p>
                        {/if}
                    </div>
                </Fieldset>

                {#each sortedDegrees as degree (degree.number)}
                    {@const degreeIndex = form.degrees.indexOf(degree)}
                    <Fieldset legend={`Secciones del ${gradeLabel(degree.number)}`}>
                        <div class="lumi-stack lumi-stack--sm">
                            {#each degree.groups as group, groupIndex (group.code ?? `new-${degree.number}-${groupIndex}`)}
                                <div class="lumi-flex lumi-flex--gap-xs lumi-align-items--end">
                                    <div class="lumi-flex-item--grow">
                                        <Input
                                            label={groupIndex === 0 ? 'Sección' : undefined}
                                            placeholder="Ej. A, A2, Grupo 1, Único"
                                            bind:value={group.name}
                                            maxlength={60}
                                            danger={!!errors[
                                                `degrees.${degreeIndex}.groups.${groupIndex}.name`
                                            ]}
                                            dangerText={errors[
                                                `degrees.${degreeIndex}.groups.${groupIndex}.name`
                                            ]}
                                        />
                                    </div>
                                    {#if degree.groups.length > 1}
                                        <Button
                                            type="button"
                                            variant="flat"
                                            color="danger"
                                            icon="trash"
                                            aria-label={`Quitar sección ${group.name || groupIndex + 1}`}
                                            onclick={() => removeGroup(degree, groupIndex)}
                                        />
                                    {/if}
                                </div>
                            {/each}

                            <div>
                                <Button
                                    type="button"
                                    variant="border"
                                    size="sm"
                                    icon="plus"
                                    onclick={() => addGroup(degree)}
                                >
                                    Agregar sección
                                </Button>
                            </div>
                        </div>
                    </Fieldset>
                {/each}

                {#if form.degrees.length === 0}
                    <p class="lumi-text--sm lumi-text--muted lumi-margin--none">
                        Selecciona al menos un grado para configurar sus secciones.
                    </p>
                {/if}
            </div>
        </Card>

        <div class="lumi-flex lumi-justify--end lumi-flex--gap-sm">
            <Button type="button" variant="border" onclick={() => router.visit('/admin/cycles')}>
                Cancelar
            </Button>
            <Button type="submit" icon="check" loading={processing}>
                {isCreate ? 'Crear ciclo' : 'Guardar ciclo'}
            </Button>
        </div>
    </form>
</div>
