<script lang="ts">
    import { onMount } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import { Alert, Avatar, Button, Card, Chip, PageHeader } from '@lumi-ui/svelte';
    import AttendanceQrCapture from './components/AttendanceQrCapture.svelte';
    import { attendanceColor, type AttendanceScanResult } from '@/types/attendance';

    interface Props {
        branch: { code: string; name: string };
        business_date: string;
        business_timezone: string;
    }

    const { branch, business_date, business_timezone }: Props = $props();

    let manualDni = $state('');
    let processing = $state(false);
    let result = $state<AttendanceScanResult | null>(null);
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
            const response = await fetch('/attendance/scan', {
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
                result?: AttendanceScanResult;
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

            result = payload.result;
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
    <title>Escanear asistencia · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title="Escanear asistencia"
        subtitle={`${branch.name} · ${business_date} · ${currentTime || '—'} · El servidor resuelve turno y estado`}
        icon="qrCode"
        size="xl"
    >
        {#snippet actions()}
            <Button
                type="button"
                variant="border"
                icon="listChecks"
                onclick={() => router.visit('/attendance')}
            >
                Asistencia
            </Button>
        {/snippet}
    </PageHeader>

    <div class="lumi-grid lumi-grid--columns-2 lumi-grid--gap-md">
        <div class="lumi-stack lumi-stack--md">
            <AttendanceQrCapture
                bind:manualValue={manualDni}
                loading={processing}
                onsubmit={submitDni}
            />

            {#if error}
                <Alert color="danger" closable onclose={() => (error = '')}>{error}</Alert>
            {/if}
        </div>

        <Card title="Resultado" subtitle="Última lectura procesada" spaced>
            <div class="lumi-scan-result">
                {#if result}
                    <Avatar text={result.student.full_name} size="xl" color="primary" />
                    <div class="lumi-stack lumi-stack--2xs lumi-text--center">
                        <h2 class="lumi-margin--none lumi-text--xl lumi-font--medium">
                            {result.student.full_name}
                        </h2>
                        <p class="lumi-margin--none lumi-text--sm lumi-text--muted">
                            DNI {result.student.dni} · Código {result.enrollment.roll_code}
                        </p>
                        <p class="lumi-margin--none lumi-text--sm lumi-text--muted">
                            {result.enrollment.cycle_name} · {result.enrollment.degree_number}° ·
                            {result.enrollment.group_name} · {result.enrollment.shift_name}
                        </p>
                    </div>
                    <div class="lumi-flex lumi-flex--gap-xs lumi-flex--wrap lumi-justify--center">
                        <Chip color={result.status === 'registered' ? 'success' : 'info'} size="sm">
                            {result.message}
                        </Chip>
                        <Chip color={attendanceColor(result.attendance.state)} size="sm">
                            {result.attendance.state_label}
                        </Chip>
                    </div>
                    <Alert
                        color={result.status === 'registered' ? 'success' : 'info'}
                        closable={false}
                    >
                        {result.status === 'registered'
                            ? 'Lectura registrada. Continúa con el siguiente carnet.'
                            : 'Esta llegada ya estaba registrada para el turno actual.'}
                    </Alert>
                {:else}
                    <Alert color="info" closable={false}>
                        Esperando un QR de carnet o un DNI de ocho dígitos para registrar
                        asistencia.
                    </Alert>
                {/if}
            </div>
        </Card>
    </div>
</div>
