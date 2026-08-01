<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import {
        Alert,
        Button,
        Dialog,
        Input,
        Select,
        Textarea,
        type SelectOption,
    } from '@lumi-ui/svelte';
    import type { AttendanceRow } from '@/types/attendance';

    type Operation = 'arrival' | 'permission' | 'justify' | 'correct';

    interface Props {
        open?: boolean;
        row: AttendanceRow | null;
        attendanceDate: string;
        onclose?: () => void;
    }

    let { open = $bindable(false), row, attendanceDate, onclose }: Props = $props();

    let operation = $state<Operation>('arrival');
    let arrivalAt = $state('');
    let reason = $state('');
    // Not named `state`: that shadows the $state rune for svelte2tsx (auto-subscription clash).
    let storedState = $state('present');
    let processing = $state(false);
    let error = $state('');

    const operationOptions = $derived<SelectOption[]>(
        row?.attendance_code
            ? [{ value: 'correct', label: 'Corregir registro' } satisfies SelectOption]
            : [
                  { value: 'arrival', label: 'Registrar llegada' },
                  { value: 'permission', label: 'Permiso' },
                  { value: 'justify', label: 'Justificar falta' },
              ],
    );

    const stateOptions: SelectOption[] = [
        { value: 'present', label: 'Presente' },
        { value: 'late', label: 'Tardanza' },
        { value: 'permission', label: 'Permiso' },
        { value: 'justified', label: 'Justificado' },
    ];

    const subtitle = $derived(
        row ? `Código ${row.roll_code} · ${row.shift_name} · ${attendanceDate}` : '',
    );

    $effect(() => {
        if (open && row) {
            operation = row.attendance_code ? 'correct' : 'arrival';
            arrivalAt = '';
            reason = row.reason ?? '';
            storedState = row.stored_state ?? 'present';
            error = '';
        }
    });

    function close(): void {
        open = false;
        onclose?.();
    }

    function submit(): void {
        if (!row || processing) {
            return;
        }
        processing = true;
        error = '';

        router.post(
            '/attendance/manual',
            {
                operation,
                enrollment_code: row.enrollment_code,
                cycle_shift_code: row.cycle_shift_code,
                attendance_date: attendanceDate,
                arrival_at: arrivalAt || null,
                reason: reason || null,
                state: operation === 'correct' ? storedState : null,
            },
            {
                preserveScroll: true,
                onError: (errors) => {
                    error =
                        errors.operation ??
                        errors.reason ??
                        errors.arrival_at ??
                        errors.state ??
                        errors.enrollment_code ??
                        'No se pudo actualizar la asistencia.';
                },
                onFinish: () => {
                    processing = false;
                },
                onSuccess: () => {
                    close();
                },
            },
        );
    }
</script>

<Dialog bind:open title={row?.full_name ?? 'Asistencia'} size="md" onclose={close}>
    {#if row}
        <form
            class="lumi-stack lumi-stack--md"
            onsubmit={(event) => {
                event.preventDefault();
                submit();
            }}
        >
            <p class="lumi-margin--none lumi-text--sm lumi-text--muted">{subtitle}</p>

            <Select
                value={operation}
                options={operationOptions}
                label="Operación"
                clearable={false}
                onchange={(value) => {
                    operation = (typeof value === 'string' ? value : 'arrival') as Operation;
                }}
            />

            {#if operation === 'arrival' || (operation === 'correct' && (storedState === 'present' || storedState === 'late'))}
                <Input
                    bind:value={arrivalAt}
                    type="time"
                    label="Hora de llegada"
                    descriptionText="Vacío = hora actual del servidor."
                    icon="clock"
                />
            {/if}

            {#if operation === 'correct'}
                <Select
                    value={storedState}
                    options={stateOptions}
                    label="Estado"
                    clearable={false}
                    onchange={(value) => {
                        storedState = typeof value === 'string' ? value : 'present';
                    }}
                />
            {/if}

            {#if operation === 'permission' || operation === 'justify' || operation === 'correct'}
                <Textarea
                    bind:value={reason}
                    label="Motivo"
                    placeholder="Describe el motivo"
                    rows={3}
                    maxlength={1000}
                    required
                />
            {/if}

            {#if error}
                <Alert color="danger">{error}</Alert>
            {/if}

            <div class="lumi-flex lumi-justify--end lumi-flex--gap-sm">
                <Button type="button" variant="border" disabled={processing} onclick={close}>
                    Cancelar
                </Button>
                <Button type="submit" icon="check" loading={processing}>Guardar</Button>
            </div>
        </form>
    {/if}
</Dialog>
