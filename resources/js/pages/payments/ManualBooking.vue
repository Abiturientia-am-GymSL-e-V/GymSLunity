<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Banknote } from '@lucide/vue';
import BookingFields from '@/components/payments/BookingFields.vue';
import PaymentsPage from '@/components/payments/PaymentsPage.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { PaymentMember } from '@/types/payments';
import { localDateString } from '@/lib/format';

defineProps<{ members: PaymentMember[] }>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Beiträge', href: '/beitraege' }] },
});

const manualForm = useForm({
    member_number: '',
    direction: 'payment' as 'payment' | 'charge',
    amount: '',
    booking_date: localDateString(),
    description: '',
    reference: '',
    // Books a double click only once; renewed after every booking.
    creation_key: crypto.randomUUID(),
});

function submit() {
    manualForm.post('/beitraege/manuell-buchen', {
        preserveScroll: true,
        onSuccess: () => (manualForm.creation_key = crypto.randomUUID()),
    });
}
</script>

<template>
    <PaymentsPage active="manual">
        <form
            class="max-w-3xl space-y-5 rounded-xl border bg-card p-5"
            @submit.prevent="submit"
        >
            <div>
                <h2 class="font-semibold">Zahlung manuell verbuchen</h2>
                <p class="text-sm text-muted-foreground">
                    Die Zahlung wird auf die ältesten offenen Beiträge verteilt.
                </p>
            </div>
            <BookingFields
                :form="manualForm"
                :members="members"
                :amount-label="
                    manualForm.direction === 'payment'
                        ? 'Zahlbetrag in Euro'
                        : 'Forderungsbetrag in Euro'
                "
                show-direction
            />
            <Button type="submit" :disabled="manualForm.processing"
                ><Spinner v-if="manualForm.processing" /><Banknote
                    v-else
                    class="size-4"
                />{{
                    manualForm.direction === 'payment'
                        ? 'Zahlung verbuchen'
                        : 'Forderung verbuchen'
                }}</Button
            >
        </form>
    </PaymentsPage>
</template>
