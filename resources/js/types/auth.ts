export interface BranchSummary {
    code: string;
    name: string;
}

export interface AuthenticatedContext {
    employee: {
        first_name: string;
        last_name: string;
        role_name: string;
    };
    branches: BranchSummary[];
    current_branch: BranchSummary | null;
    permissions: string[];
}
