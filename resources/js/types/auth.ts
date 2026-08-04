export interface BranchSummary {
    code: string;
    name: string;
}

interface AuthenticatedBase {
    branches: BranchSummary[];
    current_branch: BranchSummary | null;
    permissions: string[];
}

export interface EmployeeAuthenticatedContext extends AuthenticatedBase {
    actor: 'employee';
    employee: {
        code: string;
        first_name: string;
        last_name: string;
        role_name: string;
        photo_url: string | null;
    };
}

export interface StudentAuthenticatedContext extends AuthenticatedBase {
    actor: 'student';
    student: {
        code: string;
        first_name: string;
        last_name: string;
    };
}

export type AuthenticatedContext = EmployeeAuthenticatedContext | StudentAuthenticatedContext;
