export type FinanceMandate = {
    id: number;
    mandate_reference: string;
    debtor_name: string;
    debtor_email: string | null;
    iban: string;
    mandate_type: 'recurring' | 'one_off';
    status: 'pending' | 'signed' | 'revoked';
    signed_at: string | null;
    signature_method: string | null;
    revoked_at: string | null;
    revoked_by_name: string | null;
    revocation_reason: string | null;
    signing_url: string | null;
    created_at: string;
};

export type FinanceMandatePage = {
    data: FinanceMandate[];
    total: number;
    next_page_url: string | null;
    prev_page_url: string | null;
};

export type FinanceMandateFilters = {
    search: string;
    from: string;
    to: string;
    status: 'all' | 'pending' | 'signed' | 'revoked';
    mandate_type: 'all' | 'recurring' | 'one_off';
};
