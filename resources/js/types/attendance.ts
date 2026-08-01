export type AttendanceStoredState = 'present' | 'late' | 'permission' | 'justified';
export type AttendanceEffectiveState = AttendanceStoredState | 'pending' | 'absent';

export interface AttendanceRow {
    enrollment_code: string;
    cycle_shift_code: string;
    student_code: string;
    dni: string;
    first_name: string;
    last_name: string;
    full_name: string;
    roll_code: string;
    student_is_active: boolean;
    shift_name: string;
    attendance_code: string | null;
    stored_state: AttendanceStoredState | null;
    effective_state: AttendanceEffectiveState;
    state_label: string;
    arrival_at: string | null;
    reason: string | null;
}

export interface AttendanceSummary {
    expected: number;
    present: number;
    late: number;
    permission: number;
    justified: number;
    pending: number;
    absent: number;
}

export interface AttendanceScanResult {
    status: 'registered' | 'already_registered';
    message: string;
    student: {
        full_name: string;
        dni: string;
    };
    enrollment: {
        roll_code: string;
        cycle_name: string;
        degree_number: number;
        group_name: string;
        shift_name: string;
    };
    attendance: {
        state: AttendanceStoredState;
        state_label: string;
    };
}

/** Plain 8-digit DNI from the CR80 student card QR. */
export const ATTENDANCE_DNI_PATTERN = /^\d{8}$/;

export function attendanceColor(
    state: AttendanceEffectiveState,
): 'success' | 'warning' | 'danger' | 'info' | 'secondary' {
    if (state === 'present') return 'success';
    if (state === 'late') return 'warning';
    if (state === 'absent') return 'danger';
    if (state === 'justified') return 'info';
    if (state === 'permission') return 'secondary';
    return 'secondary';
}
