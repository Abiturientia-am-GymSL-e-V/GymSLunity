<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { RefreshCcw } from '@lucide/vue';
import BookingFields from '@/components/payments/BookingFields.vue';
import PaymentsPage from '@/components/payments/PaymentsPage.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { PaymentMember } from '@/types/payments';

defineProps<{ members: PaymentMember[] }>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Beiträge', href: '/beitraege' }] },
});

const returnForm = useForm({
    member_number: '',
    amount: '5.00',
    booking_date: new Date().toISOString().slice(0, 10),
    description: 'Rücklastschriftgebühr',
    reference: '',
});
</script>

<template>
    <PaymentsPage active="returns">
        <form
            class="max-w-3xl space-y-5 rounded-xl border bg-card p-5"
            @submit.prevent="
                returnForm.post('/beitraege/ruecklastschriften', {
                    preserveScroll: true,
                })
            "
        >
            <div>
                <h2 class="font-semibold">Rücklastschriftgebühr anlasten</h2>
                <p class="text-sm text-muted-foreground">
                    Die Gebühr wird als offener Posten auf dem Beitragskonto
                    gebucht.
                </p>
            </div>
            <BookingFields
                :form="returnForm"
                :members="members"
                amount-label="Gebühr in Euro"
            />
            <Button type="submit" :disabled="returnForm.processing"
                ><Spinner v-if="returnForm.processing" /><RefreshCcw
                    v-else
                    class="size-4"
                />Gebühr anlasten</Button
            >
        </form>
    </PaymentsPage>
</template>
