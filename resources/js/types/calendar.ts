export type CalendarShareRule = {
    id?: number;
    field_key: string;
    value: string;
};

export type ClubCalendar = {
    id: number;
    name: string;
    color: string;
    type: 'birthdays' | 'general' | 'custom';
    public_url: string | null;
    rules: CalendarShareRule[];
};

export type CalendarEvent = {
    id: string;
    event_id: number | null;
    calendar_id: number;
    calendar_name: string;
    color: string;
    title: string;
    location: string | null;
    description: string | null;
    starts_at: string;
    ends_at: string;
    all_day: boolean;
    editable: boolean;
};

export type CalendarShareField = {
    key: string;
    label: string;
    options: { value: string; label: string }[];
};
