import type { LumiColor } from '@lumi-ui/svelte';

export type EmployeeAttendanceEffectiveState =
    'present' | 'late' | 'permission' | 'justified' | 'pending' | 'absent';

export interface EmployeeAttendanceRow {
    schedule_code: string;
    schedule_weekday: number;
    schedule_entry_time: string;
    schedule_to_time: string;
    user_code: string;
    dni: string | null;
    full_name: string;
    phone: string | null;
    role_name: string | null;
    photo_url: string | null;
    attendance_code: string | null;
    attendance_state: 'present' | 'late' | 'permission' | 'justified' | null;
    attendance_entry_time: string | null;
    attendance_observation: string | null;
    attendance_created_at: string | null;
    effective_state: EmployeeAttendanceEffectiveState;
    state_label: string;
}

export interface EmployeeAttendanceHistoryRow {
    attendance_date: string;
    schedule_code: string;
    schedule_weekday: number;
    schedule_entry_time: string;
    schedule_to_time: string;
    attendance_code: string | null;
    attendance_state: string | null;
    attendance_entry_time: string | null;
    attendance_observation: string | null;
    effective_state: EmployeeAttendanceEffectiveState;
    state_label: string;
}

export interface EmployeeAttendanceScanResult {
    status: 'registered' | 'already_registered';
    message: string;
    employee: {
        code: string;
        dni: string;
        full_name: string;
        role_name: string | null;
        photo_url: string | null;
    };
    schedule: {
        code: string;
        entry_time: string;
        to_time: string;
    };
    attendance: {
        state: EmployeeAttendanceEffectiveState;
        state_label: string;
        entry_time: string;
    };
}

export interface EmployeeScheduleItem {
    code: string;
    weekday: number;
    weekday_label: string;
    entry_time: string;
    to_time: string;
    label: string;
}

export const EMPLOYEE_ATTENDANCE_STATE_OPTIONS = [
    { value: 'present', label: 'Presente' },
    { value: 'late', label: 'Tarde' },
    { value: 'permission', label: 'Permiso' },
    { value: 'justified', label: 'Justificado' },
] as const;

export function employeeAttendanceColor(state: EmployeeAttendanceEffectiveState): LumiColor {
    switch (state) {
        case 'present':
            return 'success';
        case 'late':
            return 'warning';
        case 'permission':
        case 'justified':
            return 'info';
        case 'absent':
            return 'danger';
        default:
            return 'secondary';
    }
}

export function formatScheduleWindow(entry: string, to: string): string {
    return `${entry}–${to}`;
}
