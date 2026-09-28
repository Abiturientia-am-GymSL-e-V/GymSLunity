export type BookingResource = {
    id: number;
    parent_id: number | null;
    inventory_item_id: number | null;
    name: string;
    description: string | null;
    location: string | null;
    allowed_membership_types: string[];
    auto_approve_membership_types: string[];
    price_mode: 'free' | 'once' | 'hour' | 'day';
    price_cents: number;
    is_active: boolean;
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
