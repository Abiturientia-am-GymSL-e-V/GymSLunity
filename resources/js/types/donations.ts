export type Certificate = {
    id: number;
    number: string;
    signed_at: string;
    signed_by: string;
    sent_at: string | null;
    sent_to: string | null;
    revoked_at: string | null;
    revoked_by: string | null;
    revocation_reason: string | null;
    print_only: boolean;
};

export type DonationType =
    | 'money'
    | 'material'
    | 'membership_fee'
    | 'expense_waiver';

export type Donation = {
    id: number;
    receipt_number: string;
    donor_name: string;
    donor_email: string | null;
    donation_type: DonationType;
    amount_cents: number;
    donated_at: string;
    purpose_label: string;
    description: string | null;
    certificate: Certificate | null;
};

export type DonationConfiguration = {
    ready: boolean;
    errors: string[];
    contributions_tax_deductible: boolean;
    digital_delivery_allowed: boolean;
};
