<script lang="ts">
    import type { LumiColor } from '@lumi-ui/svelte';

    interface Props {
        label: string;
        seed?: string;
    }

    const COLORS = ['primary', 'info', 'secondary'] as const satisfies readonly LumiColor[];
    const { label, seed = label }: Props = $props();

    function stableHash(value: string): number {
        let hash = 2166136261;

        for (const character of value) {
            hash ^= character.codePointAt(0) ?? 0;
            hash = Math.imul(hash, 16777619);
        }

        return hash >>> 0;
    }

    const color = $derived(COLORS[stableHash(seed) % COLORS.length] ?? 'primary');
</script>

<div class={`aeduca-branch-cover lumi-text--${color}`} title={label}>
    <svg
        class="aeduca-branch-cover__art"
        viewBox="0 0 960 280"
        preserveAspectRatio="xMidYMid slice"
        aria-hidden="true"
    >
        <circle class="aeduca-branch-cover__orb" cx="790" cy="55" r="92" />
        <path
            class="aeduca-branch-cover__land aeduca-branch-cover__land--rear"
            d="M0 210c142-52 274-42 402 6 143 53 312-55 558-8v72H0z"
        />
        <path
            class="aeduca-branch-cover__land aeduca-branch-cover__land--front"
            d="M0 246c172-35 325-8 472 22 128 26 293-26 488-9v21H0z"
        />
        <g class="aeduca-branch-cover__buildings">
            <path d="M122 223v-68l46-26 46 26v68m-67 0v-47h42v47m-59-72h76" />
            <path d="M742 218v-82l43-23 43 23v82m-64 0v-51h43v51m-65-78h86m-43-27V89m-18 0h36" />
        </g>
    </svg>

    <span class="aeduca-branch-cover__label">{label}</span>
</div>

<style>
    .aeduca-branch-cover {
        position: relative;
        width: 100%;
        height: 100%;
        min-height: 0;
        overflow: hidden;
        background:
            radial-gradient(
                circle at 82% 18%,
                color-mix(in oklch, currentColor 24%, transparent),
                transparent 36%
            ),
            linear-gradient(
                145deg,
                color-mix(in oklch, var(--lumi-color-surface-raised) 84%, currentColor) 0%,
                var(--lumi-color-surface-card) 58%,
                color-mix(in oklch, var(--lumi-color-background-secondary) 88%, currentColor) 100%
            );
    }

    .aeduca-branch-cover__art {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        pointer-events: none;
    }

    .aeduca-branch-cover__orb {
        fill: currentColor;
        opacity: 0.16;
    }

    .aeduca-branch-cover__land {
        fill: currentColor;
    }

    .aeduca-branch-cover__land--rear {
        opacity: 0.22;
    }

    .aeduca-branch-cover__land--front {
        opacity: 0.34;
    }

    .aeduca-branch-cover__buildings {
        fill: none;
        stroke: currentColor;
        stroke-linecap: round;
        stroke-linejoin: round;
        stroke-width: var(--lumi-border-width-base);
        opacity: 0.42;
    }

    .aeduca-branch-cover__label {
        position: absolute;
        top: var(--lumi-space-sm);
        left: 50%;
        max-width: calc(100% - var(--lumi-space-xl));
        padding: var(--lumi-space-2xs) var(--lumi-space-sm);
        overflow: hidden;
        transform: translateX(-50%);
        border: var(--lumi-border-width-thin) solid var(--lumi-color-border-glass);
        border-radius: var(--lumi-radius-full);
        background: color-mix(in oklch, var(--lumi-color-surface-glass-strong) 90%, transparent);
        box-shadow: var(--lumi-shadow-sm);
        color: var(--lumi-color-text);
        font-size: var(--lumi-font-size-xs);
        font-weight: var(--lumi-font-weight-semibold);
        line-height: var(--lumi-line-height-tight);
        text-overflow: ellipsis;
        white-space: nowrap;
        backdrop-filter: blur(var(--lumi-blur-sm));
    }
</style>
