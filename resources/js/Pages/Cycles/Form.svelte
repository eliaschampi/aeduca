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
        Tabs,
        TagOption,
        type SelectOption,
    } from '@lumi-ui/svelte';

    type CycleTab = 'general' | 'shifts' | 'degrees';

    interface GroupEntry {
        code: string | null;
        name: string;
    }

    interface DegreeEntry {
        number: number;
        groups: GroupEntry[];
    }

    interface DegreeOption {
        number: number;
        label: string;
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
        modality: string;
        start_date: string;
        end_date: string;
        is_active: boolean;
        shifts: { code: string; name: string; entry_time: string; tolerance_minutes: number }[];
        degrees: { number: number; groups: { code: string; name: string }[] }[];
    }

    interface Props {
        cycle?: CyclePayload | null;
        modality_options: SelectOption[];
        degree_options: DegreeOption[];
        can_manage?: boolean;
    }

    const { cycle = null, modality_options, degree_options, can_manage = false }: Props = $props();

    const isCreate = $derived(cycle === null);
    const canEdit = $derived(can_manage);

    function seedForm() {
        return {
            name: cycle?.name ?? '',
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
    let activeTab = $state<CycleTab>('general');

    const selectedNumbers = $derived(new Set(form.degrees.map((degree) => degree.number)));
    const sortedDegrees = $derived([...form.degrees].sort((a, b) => a.number - b.number));
    const tabs = $derived([
        {
            value: 'general',
            label: hasErrorFor('general') ? 'General · Revisar' : 'General',
            icon: hasErrorFor('general') ? 'alertTriangle' : 'settings',
        },
        {
            value: 'shifts',
            label: hasErrorFor('shifts') ? 'Turnos · Revisar' : 'Turnos',
            icon: hasErrorFor('shifts') ? 'alertTriangle' : 'clock',
        },
        {
            value: 'degrees',
            label: hasErrorFor('degrees') ? 'Grados y secciones · Revisar' : 'Grados y secciones',
            icon: hasErrorFor('degrees') ? 'alertTriangle' : 'graduationCap',
        },
    ]);

    function hasErrorFor(tab: CycleTab): boolean {
        const keys = Object.keys(errors);

        if (tab === 'shifts')
            return keys.some((key) => key === 'shifts' || key.startsWith('shifts.'));
        if (tab === 'degrees')
            return keys.some((key) => key === 'degrees' || key.startsWith('degrees.'));

        return keys.some((key) =>
            ['name', 'modality', 'start_date', 'end_date', 'is_active'].includes(key),
        );
    }

    function firstErrorTab(): CycleTab | null {
        if (hasErrorFor('general')) return 'general';
        if (hasErrorFor('shifts')) return 'shifts';
        if (hasErrorFor('degrees')) return 'degrees';

        return null;
    }

    function gradeLabel(number: number): string {
        return degree_options.find((option) => option.number === number)?.label ?? String(number);
    }

    function toggleGrade(number: number): void {
        if (!canEdit) return;

        if (selectedNumbers.has(number)) {
            form.degrees = form.degrees.filter((degree) => degree.number !== number);
        } else {
            form.degrees = [...form.degrees, { number, groups: [{ code: null, name: '' }] }];
        }
    }

    function addShift(): void {
        if (!canEdit || form.shifts.length >= 2) return;
        form.shifts = [
            ...form.shifts,
            { code: null, name: 'Tarde', entry_time: '13:00', tolerance_minutes: 30 },
        ];
    }

    function removeShift(index: number): void {
        if (!canEdit || form.shifts.length <= 1) return;
        form.shifts = form.shifts.filter((_, i) => i !== index);
    }

    function addGroup(degree: DegreeEntry): void {
        if (!canEdit) return;
        degree.groups = [...degree.groups, { code: null, name: '' }];
    }

    function removeGroup(degree: DegreeEntry, index: number): void {
        if (!canEdit) return;
        degree.groups = degree.groups.filter((_, i) => i !== index);
    }

    function submit(): void {
        if (processing || !canEdit) return;

        const payload = {
            name: form.name,
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
                activeTab = firstErrorTab() ?? activeTab;
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

    <Tabs bind:value={activeTab} {tabs} aria-label="Secciones del ciclo">
        <form
            class="lumi-stack lumi-stack--lg"
            onsubmit={(event) => {
                event.preventDefault();
                submit();
            }}
        >
            {#if activeTab === 'general'}
                <Card
                    title="Información general"
                    subtitle="Identidad, modalidad, estado y vigencia del ciclo."
                    spaced
                >
                    <div class="lumi-stack lumi-stack--md">
                        <div class="lumi-grid lumi-grid--responsive lumi-grid--gap-md">
                            <Input
                                label="Nombre"
                                placeholder="Ej. Primaria 2026"
                                bind:value={form.name}
                                maxlength={120}
                                required
                                disabled={!canEdit}
                                danger={!!errors.name}
                                dangerText={errors.name}
                            />
                            <Select
                                label="Modalidad"
                                bind:value={form.modality}
                                options={modality_options}
                                disabled={!canEdit}
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
                                disabled={!canEdit}
                                danger={!!errors.start_date}
                                dangerText={errors.start_date}
                            />
                            <Input
                                label="Fecha de fin"
                                type="date"
                                bind:value={form.end_date}
                                required
                                disabled={!canEdit}
                                danger={!!errors.end_date}
                                dangerText={errors.end_date}
                            />
                        </div>

                        <Switch
                            bind:checked={form.is_active}
                            label="Ciclo activo"
                            disabled={!canEdit}
                        />
                    </div>
                </Card>
            {:else if activeTab === 'shifts'}
                <Card
                    title="Turnos de asistencia"
                    subtitle="Configura uno o dos turnos con su hora de entrada y tolerancia."
                    spaced
                >
                    <div class="lumi-stack lumi-stack--md">
                        {#each form.shifts as shift, index (shift.code ?? `new-${index}`)}
                            <Fieldset legend={shift.name.trim() || `Turno ${index + 1}`}>
                                <div
                                    class="lumi-grid lumi-grid--columns-3 lumi-grid--gap-sm lumi-align-items--end"
                                >
                                    <Input
                                        label="Nombre del turno"
                                        placeholder="Ej. Mañana"
                                        bind:value={shift.name}
                                        maxlength={60}
                                        required
                                        disabled={!canEdit}
                                        danger={!!errors[`shifts.${index}.name`]}
                                        dangerText={errors[`shifts.${index}.name`]}
                                    />
                                    <Input
                                        label="Hora de entrada"
                                        type="time"
                                        bind:value={shift.entry_time}
                                        required
                                        disabled={!canEdit}
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
                                                disabled={!canEdit}
                                                danger={!!errors[
                                                    `shifts.${index}.tolerance_minutes`
                                                ]}
                                                dangerText={errors[
                                                    `shifts.${index}.tolerance_minutes`
                                                ]}
                                            />
                                        </div>
                                        {#if canEdit && form.shifts.length > 1}
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
                            </Fieldset>
                        {/each}

                        {#if canEdit && form.shifts.length < 2}
                            <div>
                                <Button
                                    type="button"
                                    variant="border"
                                    size="sm"
                                    icon="plus"
                                    onclick={addShift}
                                >
                                    Agregar segundo turno
                                </Button>
                            </div>
                        {/if}

                        {#if errors.shifts}
                            <p class="lumi-text--sm lumi-text--danger lumi-margin--none">
                                {errors.shifts}
                            </p>
                        {/if}
                    </div>
                </Card>
            {:else}
                <Card
                    title="Grados y secciones"
                    subtitle="Habilita los grados ofrecidos y configura sus secciones."
                    spaced
                >
                    <div class="lumi-stack lumi-stack--md">
                        <Fieldset legend="Grados ofrecidos">
                            <div class="lumi-stack lumi-stack--sm">
                                <div class="lumi-grid lumi-grid--columns-3 lumi-grid--gap-sm">
                                    {#each degree_options as option (option.number)}
                                        <TagOption
                                            label={option.label}
                                            active={selectedNumbers.has(option.number)}
                                            disabled={!canEdit}
                                            onclick={() => toggleGrade(option.number)}
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

                        {#each sortedDegrees as degree, degreeIndex (degree.number)}
                            <Fieldset legend={`${gradeLabel(degree.number)} grado · Secciones`}>
                                <div class="lumi-stack lumi-stack--sm">
                                    {#each degree.groups as group, groupIndex (group.code ?? `new-${degree.number}-${groupIndex}`)}
                                        <div
                                            class="lumi-flex lumi-flex--gap-xs lumi-align-items--end"
                                        >
                                            <div class="lumi-flex-item--grow">
                                                <Input
                                                    label={groupIndex === 0 ? 'Sección' : undefined}
                                                    placeholder="Ej. A, A2, Grupo 1, Único"
                                                    bind:value={group.name}
                                                    maxlength={60}
                                                    disabled={!canEdit}
                                                    danger={!!errors[
                                                        `degrees.${degreeIndex}.groups.${groupIndex}.name`
                                                    ]}
                                                    dangerText={errors[
                                                        `degrees.${degreeIndex}.groups.${groupIndex}.name`
                                                    ]}
                                                />
                                            </div>
                                            {#if canEdit && degree.groups.length > 1}
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

                                    {#if canEdit}
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
                                    {/if}
                                </div>
                            </Fieldset>
                        {/each}

                        {#if form.degrees.length === 0}
                            <p class="lumi-text--sm lumi-text--muted lumi-margin--none">
                                {canEdit
                                    ? 'Selecciona al menos un grado para configurar sus secciones.'
                                    : 'El ciclo no tiene grados configurados.'}
                            </p>
                        {/if}
                    </div>
                </Card>
            {/if}

            {#if canEdit}
                <div class="lumi-flex lumi-justify--end lumi-flex--gap-sm">
                    <Button
                        type="button"
                        variant="border"
                        onclick={() => router.visit('/admin/cycles')}
                    >
                        Cancelar
                    </Button>
                    <Button type="submit" icon="check" loading={processing}>
                        {isCreate ? 'Crear ciclo' : 'Guardar ciclo'}
                    </Button>
                </div>
            {/if}
        </form>
    </Tabs>
</div>
