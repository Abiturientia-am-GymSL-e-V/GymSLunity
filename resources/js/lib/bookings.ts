import type { BookingResource, ResourceBooking } from '@/types/bookings';

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

export function bookingStatusLabel(status: ResourceBooking['status']): string {
    return {
        requested: 'Angefragt',
        confirmed: 'Bestätigt',
        cancelled: 'Storniert',
        rejected: 'Abgelehnt',
    }[status];
}
