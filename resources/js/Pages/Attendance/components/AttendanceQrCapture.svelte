<script lang="ts">
    import { onMount } from 'svelte';
    import { Alert, Button, Card, Input } from '@lumi-ui/svelte';
    import type QrScanner from 'qr-scanner';
    import { ATTENDANCE_DNI_PATTERN } from '@/types/attendance';

    type QrScannerConstructor = typeof QrScanner;
    type QrScanResult = { data: string };

    interface Props {
        title?: string;
        subtitle?: string;
        loading?: boolean;
        disabled?: boolean;
        manualValue?: string;
        onsubmit: (dni: string) => void | Promise<void>;
    }

    let {
        title = 'Captura',
        subtitle = 'Lee el QR del carnet o escribe el DNI. El turno se resuelve en el servidor.',
        loading = false,
        disabled = false,
        manualValue = $bindable(''),
        onsubmit,
    }: Props = $props();

    let videoElement = $state<HTMLVideoElement | null>(null);
    let scanner: QrScanner | null = null;
    let scannerConstructor = $state<QrScannerConstructor | null>(null);
    let scannerReady = $state(false);
    let cameraError = $state('');
    let isStarting = $state(false);
    let scannerGeneration = 0;
    let lastScannedValue = '';
    let lastScannedAt = 0;

    const scannerStatus = $derived(
        scannerReady
            ? 'Cámara activa. Acerca el carnet al recuadro.'
            : scannerConstructor
              ? 'Cámara lista. Actívala para comenzar a leer.'
              : 'Preparando cámara…',
    );

    async function loadQrScanner(): Promise<QrScannerConstructor> {
        const module = await import('qr-scanner');
        return module.default;
    }

    /** OS camera stays on until tracks are stopped — SPA unmount does not do this alone. */
    function stopVideoTracks(video: HTMLVideoElement | null): void {
        if (!(video?.srcObject instanceof MediaStream)) {
            return;
        }
        for (const track of video.srcObject.getTracks()) {
            track.stop();
        }
        video.srcObject = null;
    }

    /** pause(true) cuts tracks synchronously; destroy() alone defers them 300ms past SPA leave. */
    function releaseScanner(instance: QrScanner): void {
        void instance.pause(true);
        instance.destroy();
    }

    async function submitValue(value: string): Promise<void> {
        const normalized = value.trim();
        if (disabled || loading) {
            return;
        }

        if (!ATTENDANCE_DNI_PATTERN.test(normalized)) {
            cameraError = 'Ingresa un DNI de ocho dígitos.';
            return;
        }

        await onsubmit(normalized);
    }

    async function handleScan(result: QrScanResult): Promise<void> {
        const digits = result.data.trim();
        if (!ATTENDANCE_DNI_PATTERN.test(digits)) {
            cameraError = 'El QR debe contener el DNI de ocho dígitos del carnet.';
            return;
        }

        // Suppress only rapid duplicates of the same DNI so different students can stream.
        const now = Date.now();
        if (digits === lastScannedValue && now - lastScannedAt < 2500) {
            return;
        }
        lastScannedValue = digits;
        lastScannedAt = now;
        cameraError = '';
        await submitValue(digits);
    }

    async function startScanner(): Promise<void> {
        const Scanner = scannerConstructor;
        if (isStarting || scannerReady || !Scanner) {
            return;
        }
        isStarting = true;
        cameraError = '';
        scannerReady = false;
        let candidate: QrScanner | null = null;
        let video: HTMLVideoElement | null = null;
        const generation = scannerGeneration;

        try {
            video = videoElement;
            if (!video) {
                return;
            }
            if (!navigator.mediaDevices?.getUserMedia) {
                cameraError = 'Este navegador no permite la cámara. Usa el DNI manual.';
                return;
            }

            const scannerCandidate = new Scanner(
                video,
                (scanResult) => {
                    void handleScan(scanResult);
                },
                {
                    preferredCamera: 'environment',
                    maxScansPerSecond: 5,
                    highlightScanRegion: true,
                    highlightCodeOutline: true,
                    returnDetailedScanResult: true,
                    onDecodeError: () => {},
                },
            );
            candidate = scannerCandidate;
            scannerCandidate.setGrayscaleWeights(85, 170, 1);
            scannerCandidate.setInversionMode('both');
            await scannerCandidate.start();
            if (generation !== scannerGeneration) {
                releaseScanner(scannerCandidate);
                return;
            }

            scanner = scannerCandidate;
            scannerReady = true;
        } catch {
            // start() failed, so _active is false and destroy() would leave the tracks running.
            candidate?.destroy();
            stopVideoTracks(video);
            if (generation !== scannerGeneration) {
                return;
            }
            cameraError = 'No se pudo iniciar la cámara. Usa el DNI manual.';
        } finally {
            isStarting = false;
        }
    }

    function stopScanner(): void {
        scannerGeneration += 1;
        const active = scanner;
        scanner = null;
        scannerReady = false;
        if (active) {
            releaseScanner(active);
        }
    }

    function handleManualSubmit(event?: SubmitEvent): void {
        event?.preventDefault();
        void submitValue(manualValue);
    }

    function handleManualInput(): void {
        // Keyboard wedge / fast typing: auto-register as soon as DNI is complete.
        const dni = manualValue.trim();
        if (ATTENDANCE_DNI_PATTERN.test(dni) && !loading && !disabled) {
            cameraError = '';
            void submitValue(dni);
        }
    }

    onMount(() => {
        let mounted = true;

        void loadQrScanner()
            .then((Scanner) => {
                if (mounted) {
                    scannerConstructor = Scanner;
                }
            })
            .catch(() => {
                if (mounted) {
                    cameraError = 'No se pudo preparar la cámara. Usa el DNI manual.';
                }
            });

        return () => {
            mounted = false;
            stopScanner();
        };
    });
</script>

<Card {title} {subtitle} spaced>
    <div class="lumi-stack lumi-stack--md">
        {#if cameraError}
            <Alert color="warning" closable onclose={() => (cameraError = '')}>{cameraError}</Alert>
        {/if}

        <div class="attendance-qr__video-shell">
            <video bind:this={videoElement} class="attendance-qr__video" playsinline muted></video>
            {#if !scannerReady}
                <div class="attendance-qr__video-action">
                    <Button
                        type="button"
                        variant="filled"
                        color="primary"
                        size="sm"
                        icon="video"
                        disabled={disabled || loading || !scannerConstructor || isStarting}
                        onclick={() => void startScanner()}
                    >
                        {isStarting
                            ? 'Iniciando cámara…'
                            : scannerConstructor
                              ? 'Activar cámara'
                              : 'Preparando cámara…'}
                    </Button>
                </div>
            {/if}
            <div class="attendance-qr__status">
                <span>{scannerStatus}</span>
            </div>
        </div>

        <form class="lumi-stack lumi-stack--sm" onsubmit={handleManualSubmit}>
            <Input
                bind:value={manualValue}
                type="text"
                label="DNI manual o lector de teclado"
                placeholder="8 dígitos"
                icon="keyboard"
                maxlength={8}
                disabled={disabled || loading}
                descriptionText="Al completar 8 dígitos o pulsar Enter se registra sin botón principal."
                oninput={handleManualInput}
            />
        </form>
    </div>
</Card>

<style>
    .attendance-qr__video-shell {
        position: relative;
        overflow: hidden;
        border-radius: var(--lumi-radius-2xl);
        border: var(--lumi-border-width-thin) solid var(--lumi-color-border);
        background:
            radial-gradient(
                circle at top,
                color-mix(in srgb, var(--lumi-color-warning) 8%, transparent),
                transparent 58%
            ),
            color-mix(
                in srgb,
                var(--lumi-color-surface) 88%,
                var(--lumi-color-background-hover) 12%
            );
        aspect-ratio: 4 / 3;
        min-block-size: calc(var(--lumi-space-3xl) * 5);
        max-inline-size: min(100%, calc(var(--lumi-space-3xl) * 10));
        margin-inline: auto;
    }

    .attendance-qr__video {
        position: relative;
        z-index: 0;
        inline-size: 100%;
        block-size: 100%;
        object-fit: cover;
    }

    .attendance-qr__video-action {
        position: absolute;
        z-index: 2;
        inset-block-start: var(--lumi-space-sm);
        inset-inline-end: var(--lumi-space-sm);
        display: flex;
        justify-content: flex-end;
    }

    .attendance-qr__status {
        position: absolute;
        inset-inline: var(--lumi-space-md);
        inset-block-end: var(--lumi-space-md);
        display: flex;
        justify-content: center;
        pointer-events: none;
    }

    .attendance-qr__status span {
        padding: var(--lumi-space-xs) var(--lumi-space-sm);
        border-radius: var(--lumi-radius-md);
        border: var(--lumi-border-width-thin) solid var(--lumi-color-border-glass);
        background: color-mix(in srgb, var(--lumi-color-surface-glass-strong) 88%, transparent);
        color: var(--lumi-color-text);
        font-size: var(--lumi-font-size-sm);
        box-shadow: var(--lumi-shadow-sm);
    }
</style>
