export type InventoryStatus = 'active' | 'sold' | 'lost' | 'disposed';

export type DepreciationMethod = 'linear' | 'immediate' | 'none';

export type InventoryItem = {
    id: number;
    inventory_number: string;
    name: string;
    category: string;
    description: string | null;
    manufacturer: string | null;
    model: string | null;
    serial_number: string | null;
    location: string;
    responsible_person: string | null;
    acquisition_type: string;
    acquisition_date: string;
    acquisition_cost_cents: number;
    document_reference: string | null;
    depreciation_method: DepreciationMethod;
    useful_life_years: number | null;
    annual_depreciation_cents: number;
    book_value_cents: number;
    status: InventoryStatus;
    disposed_at: string | null;
    disposal_proceeds_cents: number | null;
    disposal_note: string | null;
    created_by_name: string;
    disposed_by_name: string | null;
};

export type InventoryOptions = {
    categories: Record<string, string>;
    acquisitionTypes: Record<string, string>;
    depreciationMethods: Record<string, string>;
    statuses: Record<InventoryStatus, string>;
};

export type InventorySummary = {
    active_count: number;
    retired_count: number;
    acquisition_value_cents: number;
    book_value_cents: number;
};

export type InventoryDocument = {
    id: number;
    original_name: string;
    uploaded_by_name: string;
    created_at: string;
    url: string;
};
