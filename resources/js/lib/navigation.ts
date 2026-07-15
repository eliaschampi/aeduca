import type { IconName } from '@lumi-ui/svelte';

export interface NavigationItem {
    label: string;
    href: string;
    icon: IconName;
}

/** Single source of truth for sidebar. Add modules here as they land. */
export const APP_NAVIGATION: readonly NavigationItem[] = [
    {
        label: 'Inicio',
        href: '/',
        icon: 'house',
    },
];
