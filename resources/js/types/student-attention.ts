export interface StudentAttentionSubject {
    code: string;
    full_name: string;
    dni: string;
}

export interface StudentAttentionBranch {
    name: string;
}

export interface StudentAttentionTypeOption {
    value: string;
    label: string;
}

export interface StudentAttentionSummary {
    code: string;
    type_label: string;
    reason: string;
    occurred_at: string;
    author_name: string;
    files_count: number;
}

export interface StudentAttentionBranchSummary extends StudentAttentionSummary {
    student_code: string;
    student_dni: string;
    student_first_name: string;
    student_last_name: string;
}

export interface StudentAttentionDetail {
    code: string;
    type_label: string;
    reason: string;
    development: string;
    conclusion: string;
    occurred_at: string;
    created_at: string;
    updated_at: string;
    author_name: string;
    updated_by_name: string | null;
}

export interface StudentAttentionFile {
    code: string;
    name: string;
    size: number;
    deleted_at: string | null;
    serve_url: string;
}
