export interface StudentProfile {
    code: string;
    dni: string;
    first_name: string;
    last_name: string;
    birth_date: string | null;
    phone: string | null;
    address: string | null;
    observation: string | null;
    photo_url: string | null;
    is_active: boolean;
}

export interface StudentAccess {
    login: string;
    is_active: boolean;
    last_login_at: string | null;
}

export interface StudentContact {
    code: string;
    name: string;
    phone: string | null;
    note: string | null;
}

export interface EnrollmentSummary {
    code: string;
    roll_code: string;
    is_active: boolean;
    status: 'active' | 'inactive' | 'finalized';
    status_label: string;
    observation: string | null;
    academic_group_code: string;
    group_name: string;
    degree_label: string;
    cycle_name: string;
    branch_name: string;
    shift_names: string;
    created_at: string;
}
