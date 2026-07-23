export interface StudentContactData {
    code: string;
    name: string;
    phone: string | null;
    note: string | null;
}

export interface StudentData {
    code: string;
    dni: string;
    first_name: string;
    last_name: string;
    birth_date: string | null;
    phone: string | null;
    address: string | null;
    observation: string | null;
}

export interface EnrollmentProfileData {
    code: string;
    roll_code: string;
    cycle_name: string;
    branch_name: string;
    assignment: string;
    shift_names: string[];
    obligations_count: number;
    obligations_total: string;
    status: 'active' | 'inactive' | 'completed';
    status_label: string;
    created_at: string;
    can_edit: boolean;
}

export interface StudentProfileData extends StudentData {
    contacts: StudentContactData[];
    enrollments: EnrollmentProfileData[];
}
