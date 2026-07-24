<script lang="ts">
    import { onMount, type Snippet } from 'svelte';
    import { page, router } from '@inertiajs/svelte';
    import {
        Avatar,
        Button,
        Chip,
        Dropdown,
        DropdownItem,
        Icon,
        Navbar,
        Notification,
        Sidebar,
        SidebarHeader,
        SidebarItem,
    } from '@lumi-ui/svelte';
    import { ColorSchemeScript } from '@lumi-ui/svelte/color-scheme';
    import { colorScheme, colorSchemeOptions } from '@/lib/color-scheme.svelte';
    import { APP_NAVIGATION } from '@/lib/navigation';
    import { can } from '@/lib/permissions';
    import StudentSearchDialog from '@/Pages/Students/components/StudentSearchDialog.svelte';

    interface Props {
        children?: Snippet;
    }

    const MOBILE_MEDIA_QUERY = '(max-width: 64rem)';
    const NOTIFICATION_DURATION_MS = 5000;
    const THEME_LABELS = {
        system: { label: 'Sistema', icon: 'monitor' },
        light: { label: 'Claro', icon: 'sun' },
        dark: { label: 'Oscuro', icon: 'moon' },
    } as const;

    const { children }: Props = $props();

    let isMobile = $state(false);
    let sidebarCollapsed = $state(false);
    let sidebarMobileOpen = $state(false);
    let studentSearchOpen = $state(false);

    const auth = $derived(page.props.auth);
    const pathname = $derived(page.url.split('?')[0] ?? '/');
    const resolvedSidebarCollapsed = $derived(sidebarCollapsed && !isMobile);
    const resolvedSidebarMobileOpen = $derived(sidebarMobileOpen && isMobile);
    const availableNavigation = $derived(
        auth?.actor === 'student'
            ? [
                  {
                      label: 'Mi perfil',
                      href: `/students/${auth.student.code}`,
                      icon: 'user' as const,
                  },
              ]
            : APP_NAVIGATION.filter((item) => !item.permission || can(item.permission)),
    );
    // Prefer the longest matching path so /admin/employees wins over /.
    const activeNavigation = $derived.by(() => {
        const matches = availableNavigation.filter((item) => {
            const primaryMatch =
                item.href === '/' || item.exact
                    ? pathname === item.href
                    : pathname.startsWith(item.href);

            return (
                primaryMatch ||
                item.activePrefixes?.some((prefix) => pathname.startsWith(prefix)) === true
            );
        });
        if (matches.length === 0) {
            return availableNavigation[0] ?? APP_NAVIGATION[0];
        }
        return matches.reduce((best, item) => (item.href.length > best.href.length ? item : best));
    });
    const activeTheme = $derived(THEME_LABELS[colorScheme.preference]);
    const actorName = $derived(
        auth?.actor === 'student'
            ? `${auth.student.first_name} ${auth.student.last_name}`
            : auth?.actor === 'employee'
              ? `${auth.employee.first_name} ${auth.employee.last_name}`
              : 'Aeduca',
    );
    const actorMeta = $derived(
        auth?.actor === 'student'
            ? 'Alumno'
            : auth?.actor === 'employee'
              ? auth.employee.role_name
              : 'Carrión',
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
                userMeta={actorMeta}
                avatarText="AE"
            />
        {/snippet}

        {#each availableNavigation as item (item.href)}
            <SidebarItem
                href={item.href}
                active={item.href === activeNavigation.href}
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
            {#if auth?.actor === 'employee'}
                {#if can('students.view')}
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        icon="search"
                        aria-label="Buscar alumno"
                        onclick={() => (studentSearchOpen = true)}
                    />
                {/if}
                <Chip icon="building2" color="secondary" size="sm">
                    {auth.current_branch?.name ?? 'Sin sede seleccionada'}
                </Chip>
            {/if}

            <Dropdown placement="bottom-end" aria-label="Menú de usuario">
                {#snippet triggerContent()}
                    <Avatar text={actorName} size="sm" color="primary" />
                {/snippet}

                {#snippet content()}
                    <div class="lumi-padding--sm lumi-navbar-user-dropdown">
                        {#if auth}
                            <div
                                class="lumi-stack lumi-stack--2xs lumi-padding--sm lumi-border lumi-border--light lumi-min-width--0"
                            >
                                <p
                                    class="lumi-font--medium lumi-margin--none lumi-text-ellipsis"
                                    title={actorName}
                                >
                                    {actorName}
                                </p>
                                <p
                                    class="lumi-text--xs lumi-text--muted lumi-margin--none lumi-text-ellipsis"
                                    title={actorMeta}
                                >
                                    {actorMeta}
                                </p>
                            </div>
                        {/if}
                        <DropdownItem icon={activeTheme.icon} onclick={colorScheme.cyclePreference}>
                            Tema: {activeTheme.label}
                        </DropdownItem>
                        <DropdownItem icon="logOut" color="danger" onclick={logout}>
                            Cerrar sesión
                        </DropdownItem>
                    </div>
                {/snippet}
            </Dropdown>
        {/snippet}
    </Navbar>

    <main class="lumi-dashboard-layout__content">
        <div class="lumi-container lumi-container--ultrawide lumi-min-width--0">
            {#if children}
                {@render children()}
            {/if}
        </div>
    </main>
</div>

<StudentSearchDialog bind:open={studentSearchOpen} />

{#key page.flash}
    {#if page.flash.success}
        <div class="lumi-toast-portal">
            <Notification
                color="success"
                title={page.flash.success}
                duration={NOTIFICATION_DURATION_MS}
            />
        </div>
    {/if}
{/key}
