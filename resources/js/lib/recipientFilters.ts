import type { RecipientFilters } from '@/types/communication';
import type { MemberFilter, MemberFilterField } from '@/types/members';

/** Recipient status and contact data quality, offered like member fields. */
export const recipientPseudoFields: MemberFilterField[] = [
    {
        key: '__status',
        label: 'Mitgliedsstatus',
        type: 'status',
        options: {
            active: 'Aktive Mitglieder',
            contacts: 'Kontakte',
            former: 'Ausgetreten / verstorben',
            future: 'Künftige Eintritte',
            all: 'Alle Datensätze',
        },
    },
    {
        key: '__email',
        label: 'E-Mail-Adresse',
        type: 'status',
        options: { with: 'Vorhanden', without: 'Fehlt' },
    },
    {
        key: '__address',
        label: 'Postanschrift',
        type: 'status',
        options: { complete: 'Vollständig', incomplete: 'Unvollständig' },
    },
];

const pseudo = {
    __status: 'status',
    __email: 'email_status',
    __address: 'address_status',
} as const;

/** Server filters as editable builder chips (status always first). */
export function toBuilderFilters(filters: RecipientFilters): MemberFilter[] {
    const chips: MemberFilter[] = [
        { id: 1, key: '__status', value: filters.status, valueTo: '' },
    ];
    if (filters.email_status)
        chips.push({
            id: 2,
            key: '__email',
            value: filters.email_status,
            valueTo: '',
        });
    if (filters.address_status)
        chips.push({
            id: 3,
            key: '__address',
            value: filters.address_status,
            valueTo: '',
        });
    filters.fields.forEach((field, index) =>
        chips.push({
            id: 10 + index,
            key: field.key,
            value: field.value,
            valueTo: field.value_to,
        }),
    );

    return chips;
}

/** Builder chips and search text as request parameters. */
export function toRecipientFilters(
    q: string,
    chips: MemberFilter[],
): RecipientFilters {
    // Without a status chip every record is included.
    const filters: RecipientFilters = {
        q: q.trim(),
        status: 'all',
        email_status: '',
        address_status: '',
        fields: [],
    };
    for (const chip of chips) {
        if (chip.key in pseudo) {
            filters[pseudo[chip.key as keyof typeof pseudo]] = chip.value;
        } else if (chip.value || chip.valueTo) {
            filters.fields.push({
                key: chip.key,
                value: chip.value,
                value_to: chip.valueTo,
            });
        }
    }

    return filters;
}

/** Flat name/value pairs, e.g. fields[0][key], for URLs and plain forms. */
export function recipientFilterEntries(
    filters: RecipientFilters,
): [string, string][] {
    const entries: [string, string][] = [];
    if (filters.q) entries.push(['q', filters.q]);
    entries.push(['status', filters.status]);
    if (filters.email_status)
        entries.push(['email_status', filters.email_status]);
    if (filters.address_status)
        entries.push(['address_status', filters.address_status]);
    filters.fields.forEach((field, index) => {
        entries.push([`fields[${index}][key]`, field.key]);
        entries.push([`fields[${index}][value]`, field.value]);
        entries.push([`fields[${index}][value_to]`, field.value_to]);
    });

    return entries;
}
