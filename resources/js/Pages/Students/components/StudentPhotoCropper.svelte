<script lang="ts">
    import { onDestroy, tick } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import { Alert, Button, Dialog, EmptyState, Slider } from '@lumi-ui/svelte';

    interface Props {
        open?: boolean;
        studentCode: string;
        studentName: string;
    }

    const CANVAS_SIZE = 640;
    const MAX_SOURCE_BYTES = 8 * 1024 * 1024;
    const MAX_OUTPUT_BYTES = 2 * 1024 * 1024;
    const PAN_KEY_STEP = 24;

    let { open = $bindable(false), studentCode, studentName }: Props = $props();

    let fileInput: HTMLInputElement | undefined = $state();
    let canvas: HTMLCanvasElement | undefined = $state();
    let sourceImage: HTMLImageElement | null = $state(null);
    let objectUrl: string | null = $state(null);
    let zoom = $state(1);
    let panX = $state(0);
    let panY = $state(0);
    let dragX = 0;
    let dragY = 0;
    let dragging = $state(false);
    let processing = $state(false);
    let error = $state('');

    $effect(() => {
        if (zoom >= 1) draw();
    });

    function releaseImage(): void {
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = null;
        sourceImage = null;
    }

    function reset(): void {
        releaseImage();
        zoom = 1;
        panX = 0;
        panY = 0;
        dragging = false;
        processing = false;
        error = '';
        if (fileInput) fileInput.value = '';
    }

    function closeEditor(): void {
        open = false;
        reset();
    }

    async function selectFile(event: Event): Promise<void> {
        const input = event.currentTarget as HTMLInputElement;
        const file = input.files?.[0];
        if (!file) return;

        error = '';
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
            error = 'Selecciona una imagen JPG, PNG o WebP.';
            input.value = '';
            return;
        }
        if (file.size > MAX_SOURCE_BYTES) {
            error = 'La imagen original no puede superar los 8 MB.';
            input.value = '';
            return;
        }

        releaseImage();
        objectUrl = URL.createObjectURL(file);
        const image = new window.Image();
        image.src = objectUrl;

        try {
            await image.decode();
        } catch {
            error = 'No se pudo leer la imagen seleccionada.';
            releaseImage();
            return;
        }

        sourceImage = image;
        zoom = 1;
        panX = 0;
        panY = 0;
        await tick();
        draw();
    }

    function dimensions(): { width: number; height: number } | null {
        if (!sourceImage) return null;
        const coverScale = Math.max(
            CANVAS_SIZE / sourceImage.naturalWidth,
            CANVAS_SIZE / sourceImage.naturalHeight,
        );

        return {
            width: sourceImage.naturalWidth * coverScale * zoom,
            height: sourceImage.naturalHeight * coverScale * zoom,
        };
    }

    function clampPan(): void {
        const size = dimensions();
        if (!size) return;
        const limitX = Math.max(0, (size.width - CANVAS_SIZE) / 2);
        const limitY = Math.max(0, (size.height - CANVAS_SIZE) / 2);
        panX = Math.min(limitX, Math.max(-limitX, panX));
        panY = Math.min(limitY, Math.max(-limitY, panY));
    }

    function draw(): void {
        if (!canvas || !sourceImage) return;
        clampPan();
        const context = canvas.getContext('2d', { alpha: false });
        const size = dimensions();
        if (!context || !size) return;

        context.clearRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);
        context.imageSmoothingEnabled = true;
        context.imageSmoothingQuality = 'high';
        context.drawImage(
            sourceImage,
            (CANVAS_SIZE - size.width) / 2 + panX,
            (CANVAS_SIZE - size.height) / 2 + panY,
            size.width,
            size.height,
        );
    }

    function startDrag(event: PointerEvent): void {
        if (!canvas) return;
        dragging = true;
        dragX = event.clientX;
        dragY = event.clientY;
        canvas.setPointerCapture(event.pointerId);
    }

    function moveDrag(event: PointerEvent): void {
        if (!dragging || !canvas) return;
        const ratio = CANVAS_SIZE / canvas.getBoundingClientRect().width;
        panX += (event.clientX - dragX) * ratio;
        panY += (event.clientY - dragY) * ratio;
        dragX = event.clientX;
        dragY = event.clientY;
        draw();
    }

    function stopDrag(event: PointerEvent): void {
        dragging = false;
        if (canvas?.hasPointerCapture(event.pointerId)) {
            canvas.releasePointerCapture(event.pointerId);
        }
    }

    function moveWithKeyboard(event: KeyboardEvent): void {
        const movement = {
            ArrowLeft: [PAN_KEY_STEP, 0],
            ArrowRight: [-PAN_KEY_STEP, 0],
            ArrowUp: [0, PAN_KEY_STEP],
            ArrowDown: [0, -PAN_KEY_STEP],
        }[event.key];
        if (!movement) return;

        event.preventDefault();
        panX += movement[0];
        panY += movement[1];
        draw();
    }

    function canvasBlob(): Promise<Blob | null> {
        return new Promise((resolve) => canvas?.toBlob(resolve, 'image/webp', 0.86));
    }

    async function upload(): Promise<void> {
        if (!canvas || !sourceImage || processing) return;
        processing = true;
        error = '';

        const blob = await canvasBlob();
        if (!blob) {
            processing = false;
            error = 'No se pudo procesar la foto.';
            return;
        }
        if (blob.size > MAX_OUTPUT_BYTES) {
            processing = false;
            error = 'La foto optimizada supera los 2 MB. Elige otra imagen.';
            return;
        }

        const data = new FormData();
        data.set('photo', new File([blob], 'foto-alumno.webp', { type: 'image/webp' }));
        data.set('_method', 'put');

        router.post(`/students/${studentCode}/photo`, data, {
            forceFormData: true,
            preserveScroll: true,
            onError: (errors: Record<string, string>) => {
                error = errors.photo ?? 'No se pudo actualizar la foto.';
            },
            onSuccess: () => {
                open = false;
                reset();
            },
            onFinish: () => {
                processing = false;
            },
        });
    }

    onDestroy(releaseImage);
</script>

<Dialog bind:open title="Cambiar foto" size="sm" persistent={processing} onclose={reset}>
    <div class="lumi-stack lumi-stack--sm">
        <input
            bind:this={fileInput}
            class="student-photo-cropper__file"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            onchange={selectFile}
        />

        {#if error}
            <Alert color="danger">{error}</Alert>
        {/if}

        {#if sourceImage}
            <div class="lumi-stack lumi-stack--sm">
                <button
                    type="button"
                    class="student-photo-cropper__viewport"
                    aria-label={`Recorte de la foto de ${studentName}. Arrastra o usa las flechas para encuadrar.`}
                    onkeydown={moveWithKeyboard}
                >
                    <canvas
                        bind:this={canvas}
                        class:student-photo-cropper__canvas--dragging={dragging}
                        class="student-photo-cropper__canvas"
                        width={CANVAS_SIZE}
                        height={CANVAS_SIZE}
                        onpointerdown={startDrag}
                        onpointermove={moveDrag}
                        onpointerup={stopDrag}
                        onpointercancel={stopDrag}
                    ></canvas>
                </button>
                <p class="lumi-margin--none lumi-text--xs lumi-text--muted lumi-text--center">
                    Arrastra la imagen para encuadrarla. El resultado será cuadrado y optimizado.
                </p>
                <Slider
                    bind:value={zoom}
                    min={1}
                    max={3}
                    step={0.05}
                    label="Acercar"
                    showTooltip={false}
                />
            </div>
        {:else}
            <EmptyState
                icon="image"
                title="Selecciona una foto"
                description="Usa una imagen nítida; podrás ajustar el encuadre antes de guardarla."
            >
                {#snippet actions()}
                    <Button type="button" icon="upload" onclick={() => fileInput?.click()}>
                        Elegir imagen
                    </Button>
                {/snippet}
            </EmptyState>
        {/if}
    </div>

    {#snippet footer()}
        <div class="lumi-flex lumi-flex--wrap lumi-flex--gap-sm lumi-justify--end">
            {#if sourceImage}
                <Button
                    type="button"
                    variant="border"
                    icon="image"
                    disabled={processing}
                    onclick={() => fileInput?.click()}
                >
                    Elegir otra
                </Button>
            {/if}
            <Button type="button" variant="border" disabled={processing} onclick={closeEditor}>
                Cancelar
            </Button>
            <Button
                type="button"
                icon="check"
                loading={processing}
                disabled={!sourceImage}
                onclick={upload}
            >
                Guardar foto
            </Button>
        </div>
    {/snippet}
</Dialog>

<style>
    .student-photo-cropper__file {
        display: none;
    }

    .student-photo-cropper__viewport {
        width: min(100%, 17rem);
        margin-inline: auto;
        border: var(--lumi-border-width-thick) solid var(--lumi-color-border-interactive);
        border-radius: var(--lumi-radius-xl);
        box-shadow: var(--lumi-shadow-md);
        overflow: hidden;
        padding: 0;
        background: transparent;
        appearance: none;
    }

    .student-photo-cropper__viewport:focus-visible {
        outline: var(--lumi-border-width-thick) solid var(--lumi-color-primary);
        outline-offset: var(--lumi-space-2xs);
    }

    .student-photo-cropper__canvas {
        display: block;
        width: 100%;
        height: auto;
        cursor: grab;
        touch-action: none;
    }

    .student-photo-cropper__canvas--dragging {
        cursor: grabbing;
    }
</style>
