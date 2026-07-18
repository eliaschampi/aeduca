<script lang="ts">
    import { Alert, Button, Dialog, Input } from '@lumi-ui/svelte';

    interface Props {
        open: boolean;
        processing: boolean;
        error?: string | null;
        onclose: () => void;
        onsubmit: (password: string) => void;
    }

    let {
        open,
        processing,
        error = null,
        onclose,
        onsubmit,
    }: Props = $props();

    let password = $state('');
    let passwordConfirmation = $state('');
    let localError = $state<string | null>(null);

    $effect(() => {
        if (!open) {
            password = '';
            passwordConfirmation = '';
            localError = null;
        }
    });

    function submit(): void {
        if (password.length < 8) {
            localError = 'La contraseña debe tener al menos 8 caracteres.';
            return;
        }
        if (password !== passwordConfirmation) {
            localError = 'La confirmación no coincide.';
            return;
        }
        localError = null;
        onsubmit(password);
    }
</script>

<Dialog open={open} title="Cambiar contraseña" size="sm" onclose={onclose}>
    <form
        class="lumi-stack lumi-stack--md"
        onsubmit={(event) => {
            event.preventDefault();
            submit();
        }}
    >
        {#if error || localError}
            <Alert color="danger">{error ?? localError}</Alert>
        {/if}

        <Input
            label="Nueva contraseña"
            type="password"
            placeholder="Mínimo 8 caracteres"
            bind:value={password}
            required
        />
        <Input
            label="Confirmar contraseña"
            type="password"
            placeholder="Repite la contraseña"
            bind:value={passwordConfirmation}
            required
        />

        <div class="lumi-flex lumi-justify--end lumi-flex--gap-sm">
            <Button type="button" variant="border" onclick={onclose}>
                Cancelar
            </Button>
            <Button type="submit" icon="check" loading={processing}>
                Actualizar
            </Button>
        </div>
    </form>
</Dialog>
