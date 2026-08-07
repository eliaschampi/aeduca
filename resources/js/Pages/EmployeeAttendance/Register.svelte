<script lang="ts">
    import { onMount } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import { Alert, Avatar, Button, Card, Chip, PageHeader } from '@lumi-ui/svelte';
    import AttendanceQrCapture from '@/Pages/Attendance/components/AttendanceQrCapture.svelte';
    import {
        employeeAttendanceColor,
        type EmployeeAttendanceScanResult,
    } from '@/types/employee-attendance';

    interface Props {
        branch: { code: string; name: string };
        business_date: string;
        business_timezone: string;
    }

    const { branch, business_date, business_timezone }: Props = $props();

    let manualDni = $state('');
    let processing = $state(false);
    let result = $state<EmployeeAttendanceScanResult | null>(null);
    let error = $state('');
    let currentTime = $state('');

    onMount(() => {
        const formatter = new Intl.DateTimeFormat('es-PE', {
            timeZone: business_timezone,
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false,
        });
        const refresh = (): void => {
            currentTime = formatter.format(new Date());
        };
        refresh();
        const timer = window.setInterval(refresh, 1000);
        return () => window.clearInterval(timer);
    });

    async function submitDni(dni: string): Promise<void> {
        if (processing) {
            return;
        }

        processing = true;
        error = '';

        try {
            const csrfToken =
                document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
            const response = await fetch('/employee-attendance/register', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({ dni }),
            });
            const payload = (await response.json()) as {
                result?: EmployeeAttendanceScanResult;
                message?: string;
                errors?: Record<string, string[]>;
            };

            if (!response.ok || !payload.result) {
                result = null;
                error =
                    payload.message ??
                    payload.errors?.dni?.[0] ??
                    'No se pudo registrar la lectura.';
                manualDni = '';
                return;
            }

            // Assign a fresh object so Svelte 5 always treats it as a new state value.
            result = {
                status: payload.result.status,
                message: payload.result.message,
                employee: { ...payload.result.employee },
                schedule: { ...payload.result.schedule },
                attendance: { ...payload.result.attendance },
            };
            manualDni = '';
        } catch {
            result = null;
            error = 'No se pudo registrar la lectura.';
            manualDni = '';
        } finally {
            processing = false;
        }
    }
</script>

<svelte:head>
    <title>Registrar control horario · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title="Registrar control horario"
        subtitle={`${branch.name} · ${business_date} · ${currentTime || '—'} · El servidor resuelve la ventana y el estado`}
        icon="qrCode"
        size="xl"
    >
        {#snippet actions()}
            <Button
                type="button"
                variant="border"
                icon="listChecks"
                onclick={() => router.visit('/employee-attendance')}
            >
                Control horario
            </Button>
        {/snippet}
    </PageHeader>

    <div class="lumi-grid lumi-grid--columns-2 lumi-grid--gap-md">
        <div class="lumi-stack lumi-stack--md">
            <AttendanceQrCapture
                bind:manualValue={manualDni}
                loading={processing}
                subtitle="Lee el QR del carnet o escribe el DNI. La ventana de ingreso se resuelve en el servidor."
                onsubmit={submitDni}
            />

            {#if error}
                <Alert color="danger" closable onclose={() => (error = '')}>{error}</Alert>
            {/if}
        </div>

        <Card title="Resultado" subtitle="Última lectura procesada" spaced>
            <div class="lumi-scan-result">
                {#if result}
                    <Avatar
                        text={result.employee.full_name}
                        src={result.employee.photo_url ?? undefined}
                        size="xl"
                        color="primary"
                    />
                    <div class="lumi-stack lumi-stack--2xs lumi-text--center">
                        <h2 class="lumi-margin--none lumi-text--xl lumi-font--medium">
                            {result.employee.full_name}
                        </h2>
                        <p class="lumi-margin--none lumi-text--sm lumi-text--muted">
                            DNI {result.employee.dni} · {result.employee.role_name ?? 'Personal'}
                        </p>
                        <p class="lumi-margin--none lumi-text--sm lumi-text--muted">
                            Ventana {result.schedule.entry_time}–{result.schedule.to_time} · Ingreso
                            {result.attendance.entry_time}
                        </p>
                    </div>
                    <div class="lumi-flex lumi-flex--gap-xs lumi-flex--wrap lumi-justify--center">
                        <Chip color={result.status === 'registered' ? 'success' : 'info'} size="sm">
                            {result.message}
                        </Chip>
                        <Chip color={employeeAttendanceColor(result.attendance.state)} size="sm">
                            {result.attendance.state_label}
                        </Chip>
                    </div>
                    <Alert
                        color={result.status === 'registered' ? 'success' : 'info'}
                        closable={false}
                    >
                        {result.status === 'registered'
                            ? 'Ingreso registrado. Continúa con el siguiente carnet.'
                            : 'El primer ingreso se conservó; la lectura repetida no cambió el registro.'}
                    </Alert>
                {:else}
                    <Alert color="info" closable={false}>
                        Esperando un QR de carnet o un DNI de ocho dígitos para registrar el
                        ingreso.
                    </Alert>
                {/if}
            </div>
        </Card>
    </div>
</div>
