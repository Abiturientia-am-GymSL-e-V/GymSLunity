import type {
    AssignmentSnapshot,
    MemberField,
    MemberValue,
} from '@/types/members';
import countries from '../../data/countries.json';
import { formatDateTime } from '@/lib/format';
import { formatIban } from '@/lib/formatIban';

export function memberValue(
    value: MemberValue | string[] | undefined,
    field?: MemberField,
): string {
    if (isTemporal(field) || Array.isArray(value))
        return currentAssignments(value, field);
    if (value === null || value === undefined || value === '')
        return field?.emptyLabel || 'Nicht hinterlegt';
    if (typeof value === 'boolean') return value ? 'Ja' : 'Nein';
    if (field?.type === 'date')
        return String(value).slice(0, 10).split('-').reverse().join('.');
    if (field?.type === 'decimal')
        return new Intl.NumberFormat(
            'de-DE',
            field.key === 'sponsor_contribution'
                ? { style: 'currency', currency: 'EUR' }
                : { maximumFractionDigits: 2 },
        ).format(Number(value));
    if (field?.key === 'iban') return formatIban(String(value));
    if (field?.key === 'country' || field?.key === 'account_holder_country')
        return (
            (countries as Record<string, string>)[String(value)] ||
            String(value)
        );
    return field?.options[String(value)] || String(value);
}

export const TEMPORAL_TYPES = ['department', 'office', 'honor'];

export function isTemporal(field?: { type: string }): boolean {
    return TEMPORAL_TYPES.includes(field?.type ?? '');
}

/** Current options of a department, office or honor field in rank order. */
function currentAssignments(
    value: MemberValue | string[] | undefined,
    field?: MemberField,
): string {
    const values = Array.isArray(value)
        ? value
        : value === null || value === undefined || value === ''
          ? []
          : [String(value)];
    if (values.length === 0) return 'Keine';
    const rank = Object.keys(field?.options ?? {});
    const position = (option: string) =>
        rank.includes(option) ? rank.indexOf(option) : rank.length;

    return [...values]
        .sort((a, b) => position(a) - position(b))
        .map((option) => field?.options[option] || option)
        .join(', ');
}

/**
 * Filter choices of a department, office or honor field. Plain option values
 * mean "currently", the "__ever__" variants "at any time". Honors never end,
 * so "currently" already covers them.
 */
export function assignmentFilterOptions(field: {
    type: string;
    options: Record<string, string>;
}): { value: string; label: string }[] {
    const options = Object.entries(field.options);
    if (field.type === 'honor')
        return [
            { value: '__any__', label: 'Beliebige' },
            { value: '__none__', label: 'Keine' },
            ...options.map(([value, label]) => ({ value, label })),
        ];

    return [
        { value: '__any__', label: 'Aktuell: beliebige' },
        { value: '__none__', label: 'Aktuell: keine' },
        { value: '__ever__', label: 'Jemals: beliebige' },
        ...options.map(([value, label]) => ({
            value,
            label: `Aktuell: ${label}`,
        })),
        ...options.map(([value, label]) => ({
            value: `__ever__:${value}`,
            label: `Jemals: ${label}`,
        })),
    ];
}

/** Calendar date as DD.MM.YYYY, as in all assignment overviews. */
export function day(value: string): string {
    return value.slice(0, 10).split('-').reverse().join('.');
}

/** Period of an assignment, e.g. "seit 01.01.2020" or "am 01.03.2025" for honors. */
export function assignmentPeriod(
    assignment: Pick<AssignmentSnapshot, 'starts_on' | 'ends_on'>,
    type?: string,
): string {
    const { starts_on: start, ends_on: end } = assignment;
    if (type === 'honor') return start ? `am ${day(start)}` : 'Datum unbekannt';
    if (start && end) return `${day(start)} – ${day(end)}`;
    if (end) return `Beginn unbekannt – ${day(end)}`;

    return start ? `seit ${day(start)}` : 'Beginn unbekannt';
}

/** One line per assignment for history values of department, office and honor fields. */
export function assignmentLines(
    value: MemberValue | AssignmentSnapshot[] | undefined,
    field?: MemberField,
): string[] | null {
    if (!Array.isArray(value) && !isTemporal(field)) return null;
    // Single values from before a field became an office field.
    if (typeof value === 'string' && value !== '')
        return [field?.options[value] || value];
    if (!Array.isArray(value) || value.length === 0) return ['Keine'];

    return value.map((assignment) => {
        const label = field?.options[assignment.option] || assignment.option;
        const note = assignment.note ? ` – ${assignment.note}` : '';

        return `${label} (${assignmentPeriod(assignment, field?.type)})${note}`;
    });
}

export function memberTimestamp(value: string): string {
    return formatDateTime(value);
}
