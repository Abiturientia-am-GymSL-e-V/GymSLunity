import type { BookingResource, ResourceBooking } from '@/types/bookings';
import { formatMoney } from '@/lib/format';

/** Resource name with its parents, e.g. "Vereinsheim › Saal". */
export function resourceLabel(
    resource: BookingResource,
    resources: BookingResource[],
): string {
    const labels = [resource.name];
    let parent = resources.find((item) => item.id === resource.parent_id);
    while (parent) {
        labels.unshift(parent.name);
        parent = resources.find((item) => item.id === parent?.parent_id);
    }
    return labels.join(' › ');
}

const unitLabel = (value: number, unit: string) => {
    const labels = {
        minutes: value === 1 ? 'Minute' : 'Minuten',
        hours: value === 1 ? 'Stunde' : 'Stunden',
        days: value === 1 ? 'Tag' : 'Tage',
    };
    return `${value} ${labels[unit as keyof typeof labels]}`;
};

export function bookingPriceLabel(resource: BookingResource): string {
    if (resource.price_mode === 'free') return 'Kostenlos';
    if (resource.price_mode === 'once')
        return `${formatMoney(resource.price_cents)} einmalig`;
    if (resource.price_mode === 'hour')
        return `${formatMoney(resource.price_cents)} je Stunde`;
    if (resource.price_mode === 'day')
        return `${formatMoney(resource.price_cents)} je Tag`;
    return resource.pricing_rules
        .map(
            (rule) =>
                `${formatMoney(rule.price_cents)} je ${unitLabel(rule.unit_value, rule.unit)}` +
                (rule.from_value
                    ? ` ab ${unitLabel(rule.from_value, rule.from_unit)}`
                    : ''),
        )
        .join(' · ');
}

export function bookingStatusLabel(status: ResourceBooking['status']): string {
    return {
        requested: 'Angefragt',
        confirmed: 'Bestätigt',
        cancelled: 'Storniert',
        rejected: 'Abgelehnt',
    }[status];
}
