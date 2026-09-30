import type { AssignmentOption } from '@/types/members';

/** One assignment in the office, department and honor overviews. */
export type AssignmentRow = {
    id: number;
    member_number: number;
    name: string;
    current_member: boolean;
    option: string;
    starts_on: string | null;
    ends_on: string | null;
    note: string | null;
};
export type OfficeOption = AssignmentOption & {
    holders: AssignmentRow[];
    vacant: boolean;
    exceeded: boolean;
};
export type OfficeGroup = {
    key: string;
    label: string;
    archived: boolean;
    options: OfficeOption[];
};
export type DepartmentGroup = {
    key: string;
    label: string;
    archived: boolean;
    options: {
        value: string;
        label: string;
        active: boolean;
        members: number;
        joined: number;
        left: number;
    }[];
};
export type HonorRow = AssignmentRow & { field: string; label: string };
export type AssignmentFilters = {
    field: string;
    option: string;
    date: string;
    from: string | null;
    to: string | null;
    year: number | null;
    board: boolean;
    members: 'all' | 'current';
};
/** Members due for an honor with a jubilee rule. */
export type JubileeGroup = {
    field: string;
    field_label: string;
    option: string;
    label: string;
    years: number;
    members: {
        member_number: number;
        name: string;
        current_member: boolean;
        joined_at: string;
        jubilee_on: string;
        due: boolean;
    }[];
};
