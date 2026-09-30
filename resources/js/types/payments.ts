export type PaymentMember = {
    member_number: number;
    name: string;
    first_name: string;
    last_name: string;
    email: string | null;
    payment_method: string | null;
    mandate_reference?: string | null;
    missing?: string[];
};

export type Contribution = {
    id: number;
    member_number: number;
    member_name: string;
    email: string | null;
    description: string;
    payment_reference: string | null;
    kind: string;
    amount_cents: number;
    remaining_cents: number;
    period_start: string;
    period_end: string;
    due_date: string;
    invoice_number: string | null;
    invoice_sent_at: string | null;
    mandate_sequence: string | null;
    sepa_ready: boolean;
};

export type ContributionFilterField = {
    key: string;
    label: string;
    type:
        | 'text'
        | 'email'
        | 'tel'
        | 'date'
        | 'number'
        | 'decimal'
        | 'select'
        | 'boolean'
        | 'department'
        | 'office'
        | 'honor';
    options: Record<string, string>;
};

export type BankImportRow = {
    id: number;
    payment_import_id: number;
    row_number: number;
    booking_date: string | null;
    amount_cents: number;
    purpose: string | null;
    reference: string | null;
    type: 'payment' | 'return_debit';
    reason: string;
    original_name: string;
};

export type RecentImport = {
    id: number;
    original_name: string;
    row_count: number;
    imported_count: number;
    unmatched_count: number;
    created_at: string;
};

export type Transaction = {
    id: number;
    member_number: number;
    member_name: string;
    kind: string;
    amount_cents: number;
    booking_date: string;
    description: string;
    reference: string | null;
};

export type OpenDebtor = {
    member_number: number;
    member_name: string;
    email: string | null;
    address_ready: boolean;
    open_count: number;
    open_cents: number;
    overdue_count: number;
    overdue_cents: number;
    earliest_due_date: string | null;
};

export type PaymentsClub = {
    tax_deductible_enabled: boolean;
    sepa_ready: boolean;
};

export type PaymentsTab =
    | 'overview'
    | 'mandates'
    | 'create'
    | 'invoices'
    | 'dunning'
    | 'sepa'
    | 'bank'
    | 'returns'
    | 'manual';
