<script lang="ts">
    import { onMount, type Snippet } from 'svelte';
    import { page, router } from '@inertiajs/svelte';
    import {
        Avatar,
        Dropdown,
        DropdownItem,
        Icon,
        Navbar,
        Sidebar,
        SidebarHeader,
        SidebarItem,
    } from '@lumi-ui/svelte';
    import { ColorSchemeScript } from '@lumi-ui/svelte/color-scheme';
    import { colorScheme, colorSchemeOptions } from '@/lib/color-scheme.svelte';
    import { APP_NAVIGATION } from '@/lib/navigation';

    interface Props {
        children: Snippet;
    }

    const MOBILE_MEDIA_QUERY = '(max-width: 64rem)';
    const THEME_LABELS = {
        system: { label: 'Sistema', icon: 'monitor' },
        light: { label: 'Claro', icon: 'sun' },
        dark: { label: 'Oscuro', icon: 'moon' },
    } as const;

    const { children }: Props = $props();

    let isMobile = $state(false);
    let sidebarCollapsed = $state(false);
    let sidebarMobileOpen = $state(false);

    const pathname = $derived(page.url.split('?')[0] ?? '/');
    const resolvedSidebarCollapsed = $derived(sidebarCollapsed && !isMobile);
    const resolvedSidebarMobileOpen = $derived(sidebarMobileOpen && isMobile);
    const activeNavigation = $derived(
        APP_NAVIGATION.find((item) =>
            item.href === '/' ? pathname === '/' : pathname.startsWith(item.href),
        ) ?? APP_NAVIGATION[0],
    );
    const activeTheme = $derived(THEME_LABELS[colorScheme.preference]);

    onMount(() => {
        const stopScheme = colorScheme.start();
        const mediaQuery = window.matchMedia(MOBILE_MEDIA_QUERY);

        const syncViewport = (): void => {
            isMobile = mediaQuery.matches;
            if (!isMobile) sidebarMobileOpen = false;
        };

        syncViewport();
        mediaQuery.addEventListener('change', syncViewport);

        return () => {
            stopScheme();
            mediaQuery.removeEventListener('change', syncViewport);
        };
    });


    function toggleSidebar(): void {
        if (isMobile) {
            sidebarMobileOpen = !sidebarMobileOpen;
            return;
        }
        sidebarCollapsed = !sidebarCollapsed;
    }

    function closeMobileSidebar(): void {
        sidebarMobileOpen = false;
    }

    function visitLink(event: MouseEvent, href: string): void {
        event.preventDefault();
        closeMobileSidebar();
        router.visit(href);
    }
</script>

<ColorSchemeScript {...colorSchemeOptions} />

<div
    class="lumi-dashboard-layout"
    class:lumi-sidebar--is-collapsed={resolvedSidebarCollapsed}
    class:lumi-sidebar--mobile-open={resolvedSidebarMobileOpen}
>
    {#if resolvedSidebarMobileOpen}
        <button
            type="button"
            class="lumi-mobile-overlay lumi-border--none"
            aria-label="Cerrar navegación"
            onclick={closeMobileSidebar}
        ></button>
    {/if}

    <Sidebar
        collapsed={resolvedSidebarCollapsed}
        mobileOpen={resolvedSidebarMobileOpen}
        ariaLabel="Navegación de Aeduca"
    >
        {#snippet header()}
            <SidebarHeader
                collapsed={resolvedSidebarCollapsed}
                userName="Aeduca"
                userMeta="Sistema educativo"
                avatarText="AE"
            />
        {/snippet}

        {#each APP_NAVIGATION as item (item.href)}
            <SidebarItem
                href={item.href}
                active={item.href === '/' ? pathname === '/' : pathname.startsWith(item.href)}
                collapsed={resolvedSidebarCollapsed}
                onclick={(event) => visitLink(event, item.href)}
            >
                {#snippet icon()}
                    <Icon icon={item.icon} />
                {/snippet}
                {item.label}
            </SidebarItem>
        {/each}
    </Sidebar>

    <Navbar ontoggle-sidebar={toggleSidebar}>
        {#snippet title()}
            {activeNavigation.label}
        {/snippet}

        {#snippet actions()}
            <Dropdown placement="bottom-end" aria-label="Menú de usuario">
                {#snippet triggerContent()}
                    <Avatar text="Aeduca" size="sm" color="primary" />
                {/snippet}

                <DropdownItem icon={activeTheme.icon} onclick={colorScheme.cyclePreference}>
                    Tema: {activeTheme.label}
                </DropdownItem>
            </Dropdown>
        {/snippet}
    </Navbar>

    <main class="lumi-dashboard-layout__content">
        <div class="lumi-container lumi-container--ultrawide lumi-min-width--0">
            {@render children()}
        </div>
    </main>
</div>
