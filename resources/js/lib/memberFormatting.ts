import type { MemberField, MemberValue } from '@/types/members';
import countries from '../../data/countries.json';
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

export function memberTimestamp(value: string): string {
    return new Intl.DateTimeFormat('de-DE', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}
