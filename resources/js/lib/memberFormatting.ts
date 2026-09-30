import type {
    AssignmentSnapshot,
    MemberField,
    MemberValue,
} from '@/types/members';
import countries from '../../data/countries.json';
import { formatDateTime } from '@/lib/format';
import { formatIban } from '@/lib/formatIban';

export function memberValue(
    value: MemberValue | undefined,
    field?: MemberField,
): string {
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

const TEMPORAL_TYPES = ['department', 'office', 'honor'];

function day(value: string): string {
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
    if (!Array.isArray(value) && !TEMPORAL_TYPES.includes(field?.type ?? ''))
        return null;
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
