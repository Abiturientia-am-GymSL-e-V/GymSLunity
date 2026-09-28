export type FinanceStatistics = {
    contributions: {
        count: number;
        assessed_cents: number;
        paid_cents: number;
        open_cents: number;
        overdue_count: number;
        overdue_cents: number;
        collection_rate: number;
    };
    donations: {
        count: number;
        amount_cents: number;
        average_cents: number;
        by_type: { label: string; count: number; amount_cents: number }[];
    };
    monthly: {
        key: string;
        label: string;
        contributions_cents: number;
        donations_cents: number;
    }[];
};

export type DataQuality = {
    score: number;
    checks: {
        key: string;
        label: string;
        description: string;
        count: number;
        percentage: number;
    }[];
};
