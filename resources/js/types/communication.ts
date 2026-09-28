export type CommunicationTab = 'mail' | 'letters' | 'history';

export type RecipientFilters = {
    q: string;
    status: string;
    membership: string;
    department_role: string;
    club_role: string;
    gender: string;
    payment_method: string;
    city: string;
    honorary: string;
    email_status: string;
    address_status: string;
    joined_from: string;
    joined_to: string;
    custom: Record<string, string>;
};

export type RecipientFilterOptions = {
    memberships: string[];
    departmentRoles: string[];
    clubRoles: string[];
    paymentMethods: string[];
    cities: string[];
};

export type RecipientSummary = {
    total: number;
    with_email: number;
    without_email: number;
    complete_address: number;
    incomplete_address: number;
};

export type RecipientPreviewRow = {
    member_number: number;
    name: string;
    email: string | null;
    city: string | null;
    membership_type: string;
    email_ready: boolean;
    address_ready: boolean;
};

export type Campaign = {
    id: number;
    kind: 'mail' | 'letter';
    format: string | null;
    subject: string;
    recipient_count: number;
    skipped_count: number;
    success_count: number;
    failure_count: number;
    created_by_name: string;
    created_at: string;
    attachments: { name: string; mime: string; size: number }[];
};

export type Delivery = {
    id: number;
    member_number: number;
    recipient_name: string;
    recipient_email: string | null;
    status: 'pending' | 'sent' | 'failed' | 'generated';
    error: string | null;
};
