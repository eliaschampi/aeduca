export interface StudentAttentionBranch {
    name: string;
}

export interface StudentAttentionStudent {
    code: string;
    full_name: string;
    dni: string;
}

export interface StudentAttentionTypeOption {
    value: string;
    label: string;
}

export interface StudentAttentionAttachment {
    code: string;
    name: string;
    size: number;
    deleted_at: string | null;
    serve_url: string | null;
}

export interface StudentAttentionSummary {
    code: string;
    student_code: string;
    student_dni: string;
    student_first_name: string;
    student_last_name: string;
    type_label: string;
    reason: string;
    occurred_at: string;
    author_name: string;
    has_attachment: boolean;
}

export interface StudentAttentionCertificate {
    attention: {
        code: string;
        type_label: string;
        reason: string;
        development: string;
        conclusion: string;
        occurred_at: string;
        has_attachment: boolean;
    };
    student: StudentAttentionStudent;
    branch: StudentAttentionBranch;
    author: {
        full_name: string;
        role_name: string | null;
    };
    business_timezone: string;
    generated_at: string;
}
