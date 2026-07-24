import type { IconName } from '@lumi-ui/svelte';

export interface NavigationItem {
    label: string;
    href: string;
    icon: IconName;
    permission?: string;
    exact?: boolean;
    activePrefixes?: readonly string[];
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
        label: 'Alumnos',
        href: '/students/search',
        icon: 'graduationCap',
        permission: 'students.view',
        activePrefixes: ['/students/'],
    },
    {
        label: 'Matriculados',
        href: '/students',
        icon: 'listChecks',
        permission: 'enrollments.view',
        exact: true,
        activePrefixes: ['/enrollments/'],
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
