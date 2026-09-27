import { usePage } from '@inertiajs/vue3';

export function address(informal: string, formal: string): string {
    return usePage().props.formOfAddress === 'sie' ? formal : informal;
}
