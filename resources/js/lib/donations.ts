import type { DonationType } from '@/types/donations';

export const donationTypeLabels: Record<DonationType, string> = {
    money: 'Geldzuwendung',
    material: 'Sachzuwendung',
    membership_fee: 'Mitgliedsbeitrag',
    expense_waiver: 'Aufwandsspende',
};

export const donationTypeOptions = Object.entries(donationTypeLabels).map(
    ([value, label]) => ({ value, label }),
);
