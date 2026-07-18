import { createInertiaApp, type ResolvedComponent } from '@inertiajs/svelte';
import { mount } from 'svelte';
import '@lumi-ui/svelte/styles';
import './styles/lumi-theme.css';
import DashboardLayout from './Layouts/DashboardLayout.svelte';
import { installInertiaLinkDelegation } from '@/lib/inertia-links';

interface PageModule {
    default: ResolvedComponent['default'];
    layout?: ResolvedComponent['layout'] | false;
}

const pages = import.meta.glob<PageModule>('./Pages/**/*.svelte', { eager: true });

/** Dispose previous click delegate if setup re-runs (keeps one listener, O(1) cost). */
let disposeLinkDelegation: (() => void) | undefined;

createInertiaApp({
    resolve: (name): ResolvedComponent => {
        const page = pages[`./Pages/${name}.svelte`];

        if (!page?.default) {
            throw new Error(`Inertia page not found: ${name}`);
        }

        if (page.layout === false) {
            return { default: page.default };
        }

        return { default: page.default, layout: page.layout ?? DashboardLayout };
    },
    setup({ el, App, props }) {
        mount(App, { target: el!, props });
        // SvelteKit-like: plain same-origin <a href> → Inertia visit (QuickAccessCard, etc.)
        disposeLinkDelegation?.();
        disposeLinkDelegation = installInertiaLinkDelegation();
    },
    progress: {
        color: 'var(--lumi-color-primary)',
    },
});
