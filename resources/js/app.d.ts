/// <reference types="svelte" />
/// <reference types="vite/client" />

import type { AuthenticatedContext } from './types/auth';

declare module '*.svelte' {
    import type { Component } from 'svelte';
    const component: Component;
    export default component;
}

declare module '@inertiajs/core' {
    interface InertiaConfig {
        sharedPageProps: {
            auth: AuthenticatedContext | null;
        };
    }
}

declare module '@lumi-ui/svelte/styles';
declare module '*.css';

export {};
