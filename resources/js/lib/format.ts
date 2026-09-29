const currencyFormats = new Map<string, Intl.NumberFormat>();
const numberFormat = new Intl.NumberFormat('de-DE');
const dateFormat = new Intl.DateTimeFormat('de-DE');
const dateTimeFormat = new Intl.DateTimeFormat('de-DE', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

/** Amount in cents as German currency, e.g. 1234 → "12,34 €". */
export function formatMoney(cents: number, currency = 'EUR'): string {
    let format = currencyFormats.get(currency);
    if (!format) {
        format = new Intl.NumberFormat('de-DE', {
            style: 'currency',
            currency,
        });
        currencyFormats.set(currency, format);
    }

    return format.format(cents / 100);
}

/**
 * Calendar date (YYYY-MM-DD or an ISO timestamp) as a German date, read as a
 * local date so it never shifts by a day; "–" when empty.
 */
export function formatDate(value: string | null | undefined): string {
    return value
        ? dateFormat.format(new Date(`${value.slice(0, 10)}T00:00:00`))
        : '–';
}

/**
 * Timestamp (ISO or database "YYYY-MM-DD HH:MM:SS") as German date and
 * time, e.g. "28.09.2026, 14:30".
 */
export function formatDateTime(value: string): string {
    return dateTimeFormat.format(new Date(value.replace(' ', 'T')));
}

/**
 * Local calendar date as YYYY-MM-DD. Unlike toISOString(), which is UTC, this
 * does not return yesterday's date shortly after midnight.
 */
export function localDateString(date: Date = new Date()): string {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

/** Integer or decimal with German grouping, e.g. 1234 → "1.234". */
export function formatNumber(value: number): string {
    return numberFormat.format(value);
}
