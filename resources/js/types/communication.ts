export type CommunicationTab = 'mail' | 'letters' | 'history';

export type RecipientFieldFilter = {
    key: string;
    value: string;
    value_to: string;
};

export type RecipientFilters = {
    q: string;
    status: string;
    email_status: string;
    address_status: string;
    fields: RecipientFieldFilter[];
};

export type RecipientSummary = {
    total: number;
    with_email: number;
    without_email: number;
    complete_address: number;
    incomplete_address: number;
};

export type RecipientPreviewPage = {
    data: RecipientPreviewRow[];
    total: number;
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
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
    kind: 'mail' | 'letter' | 'welcome';
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
    status: 'pending' | 'sent' | 'failed' | 'generated' | 'skipped';
    error: string | null;
};
