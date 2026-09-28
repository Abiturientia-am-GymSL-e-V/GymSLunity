export type BookingResource = {
    id: number;
    parent_id: number | null;
    inventory_item_id: number | null;
    name: string;
    description: string | null;
    location: string | null;
    allowed_membership_types: string[];
    auto_approve_membership_types: string[];
    access_rules: BookingRule[];
    auto_approve_rules: BookingRule[];
    price_mode: 'free' | 'once' | 'duration' | 'hour' | 'day';
    price_cents: number;
    pricing_rules: PricingRule[];
    is_active: boolean;
};

export type BookingRule = { field_key: string; value: string };

export type BookingMemberField = {
    key: string;
    label: string;
    options: { value: string; label: string }[];
};

export type PricingRule = {
    from_value: number;
    from_unit: 'minutes' | 'hours' | 'days';
    unit_value: number;
    unit: 'minutes' | 'hours' | 'days';
    price_cents: number;
};

export type ResourceBooking = {
    id: number;
    resource_id: number;
    resource_name: string;
    member_id: number | null;
    member_number: number | null;
    requester_name: string;
    title: string;
    notes: string | null;
    starts_at: string;
    ends_at: string;
    series_id: string | null;
    occurrence: number;
    status: 'requested' | 'confirmed' | 'cancelled' | 'rejected';
    price_cents: number;
    finance_invoice_id: number | null;
    created_by_name: string | null;
    decided_by_name: string | null;
};

export type BookingMemberOption = {
    id: number;
    label: string;
    membership_type: string;
};

export type BookableInventoryItem = {
    id: number;
    number: string;
    name: string;
    description: string | null;
    location: string;
};
