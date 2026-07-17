import { createInertiaApp, type ResolvedComponent } from '@inertiajs/svelte';
import { mount } from 'svelte';
import '@lumi-ui/svelte/styles';
import './styles/lumi-theme.css';
import DashboardLayout from './Layouts/DashboardLayout.svelte';

interface PageModule {
    default: ResolvedComponent['default'];
    layout?: ResolvedComponent['layout'] | false;
}

const pages = import.meta.glob<PageModule>('./Pages/**/*.svelte', { eager: true });

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
    },
    progress: {
        color: 'var(--lumi-color-primary)',
    },
});
