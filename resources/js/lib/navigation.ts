import type { IconName } from '@lumi-ui/svelte';

export interface NavigationItem {
    label: string;
    href: string;
    icon: IconName;
    permission?: string;
}

/**
 * Sidebar source of truth.
 * Keep short, no duplicate modules. Labels are end-user Spanish copy.
 * Permission keys stay semantic (`employees.*`) — not renamed with the UI label.
 */
export const APP_NAVIGATION: readonly NavigationItem[] = [
    {
        label: 'Inicio',
        href: '/',
        icon: 'house',
        permission: 'dashboard.view',
    },
    {
        label: 'Sedes',
        href: '/branches',
        icon: 'building2',
    },
    {
        label: 'Ciclos',
        href: '/admin/cycles',
        icon: 'bookOpen',
        permission: 'cycles.view',
    },
    {
        label: 'Estudiantes',
        href: '/students',
        icon: 'graduationCap',
        permission: 'students.view',
    },
    {
        label: 'Usuarios',
        href: '/admin/employees',
        icon: 'users',
        permission: 'employees.view',
    },
    {
        label: 'Roles',
        href: '/admin/roles',
        icon: 'shield',
        permission: 'roles.view',
    },
];
