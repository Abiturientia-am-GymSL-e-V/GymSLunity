import type { MemberField, MemberSort } from '@/types/members';

export type MemberColumnKey =
    | 'number'
    | 'name'
    | 'contact'
    | 'location'
    | 'membership'
    | 'roles'
    | 'address'
    | 'birth_date'
    | 'joined_at'
    | 'left_at'
    | 'honorary'
    | `custom_${string}`;
export type MemberColumn = {
    key: MemberColumnKey;
    label: string;
    sort?: MemberSort;
    required?: boolean;
    defaultVisible?: boolean;
    field?: MemberField;
};

export function columnsFor(fields: MemberField[]): MemberColumn[] {
    const label = (key: string, fallback: string) =>
        fields.find((field) => field.key === key)?.label || fallback;
    const active = new Set(fields.map((field) => field.key));
    const columns: MemberColumn[] = [
        {
            key: 'number',
            label: 'Mitgliedsnummer',
            sort: 'member_number',
            required: true,
        },
        { key: 'name', label: 'Name', sort: 'name', required: true },
        { key: 'contact', label: 'Kontakt', defaultVisible: true },
        {
            key: 'location',
            label: 'Wohnort',
            sort: 'city',
            defaultVisible: true,
        },
        {
            key: 'membership',
            label: label('membership_type', 'Mitgliedschaft'),
            sort: 'membership_type',
            defaultVisible: true,
        },
        { key: 'roles', label: 'Funktionen', defaultVisible: true },
        { key: 'address', label: 'Straße / Land' },
        {
            key: 'birth_date',
            label: label('birth_date', 'Geburtsdatum'),
            sort: 'birth_date',
        },
        {
            key: 'joined_at',
            label: label('joined_at', 'Eintritt'),
            sort: 'joined_at',
        },
        {
            key: 'left_at',
            label: label('left_at', 'Austritt'),
            sort: 'left_at',
        },
        { key: 'honorary', label: label('is_honorary', 'Ehrenmitglied') },
        ...fields
            .filter((field) => field.custom)
            .map((field) => ({
                key: field.key as MemberColumnKey,
                label: field.label,
                sort: field.key as MemberSort,
                defaultVisible: field.showInTable,
                field,
            })),
    ];
    const sources: Record<string, string[]> = {
        contact: ['email', 'mobile_phone'],
        location: ['city', 'postal_code'],
        roles: ['department_role', 'club_role'],
        address: ['street', 'country'],
        honorary: ['is_honorary'],
    };
    return columns.filter(
        (column) =>
            column.required ||
            column.key === 'membership' ||
            (sources[column.key] || [column.key]).some((key) =>
                active.has(key),
            ),
    );
}
