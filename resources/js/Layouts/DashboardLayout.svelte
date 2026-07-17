<script lang="ts">
    import { onMount, type Snippet } from 'svelte';
    import { page, router } from '@inertiajs/svelte';
    import {
        Avatar,
        Chip,
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
    import { can } from '@/lib/permissions';

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

    const auth = $derived(page.props.auth);
    const pathname = $derived(page.url.split('?')[0] ?? '/');
    const resolvedSidebarCollapsed = $derived(sidebarCollapsed && !isMobile);
    const resolvedSidebarMobileOpen = $derived(sidebarMobileOpen && isMobile);
    const availableNavigation = $derived(
        APP_NAVIGATION.filter((item) => !item.permission || can(item.permission)),
    );
    // Prefer the longest matching path so /admin/employees wins over /.
    const activeNavigation = $derived.by(() => {
        const matches = availableNavigation.filter((item) =>
            item.href === '/' ? pathname === '/' : pathname.startsWith(item.href),
        );
        if (matches.length === 0) {
            return availableNavigation[0] ?? APP_NAVIGATION[0];
        }
        return matches.reduce((best, item) =>
            item.href.length > best.href.length ? item : best,
        );
    });
    const activeTheme = $derived(THEME_LABELS[colorScheme.preference]);
    const employeeName = $derived(
        auth ? `${auth.employee.first_name} ${auth.employee.last_name}` : 'Aeduca',
    );
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

    function logout(): void {
        router.delete('/logout');
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
                userMeta={auth?.employee.role_name ?? 'Carrión'}
                avatarText="AE"
            />
        {/snippet}

        {#each availableNavigation as item (item.href)}
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
            {#if auth}
                <Chip icon="building2" color="secondary" size="sm">
                    {auth.current_branch?.name ?? 'Sin sede seleccionada'}
                </Chip>
            {/if}

            <Dropdown placement="bottom-end" aria-label="Menú de usuario">
                {#snippet triggerContent()}
                    <Avatar text={employeeName} size="sm" color="primary" />
                {/snippet}

                {#if auth}
                    <DropdownItem icon="user" disabled>
                        {employeeName} · {auth.employee.role_name}
                    </DropdownItem>
                {/if}
                <DropdownItem icon={activeTheme.icon} onclick={colorScheme.cyclePreference}>
                    Tema: {activeTheme.label}
                </DropdownItem>
                <DropdownItem icon="logOut" color="danger" onclick={logout}>
                    Cerrar sesión
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
