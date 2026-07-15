import type { ColorSchemeOptions } from '@lumi-ui/svelte/color-scheme';

/** Single source of truth — Lumi GUIDE §3: same object for script + controller. */
export const colorSchemeOptions = {
    storageKey: 'aeduca-color-scheme',
    defaultPreference: 'system',
} satisfies ColorSchemeOptions;
