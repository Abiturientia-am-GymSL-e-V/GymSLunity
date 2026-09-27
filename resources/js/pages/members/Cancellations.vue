<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { reactive } from 'vue';
import MemberPageHeader from '@/components/members/MemberPageHeader.vue';
import MembersNav from '@/components/members/MembersNav.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Cancellation = {
    member_number: number;
    first_name: string;
    last_name: string;
    email: string;
    membership_type: string;
    requested_at: string;
    lock_version: number;
};

const props = defineProps<{
    totalMembers: number;
    today: string;
    cancellations: {
        data: Cancellation[];
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Mitglieder', href: '/mitglieder' },
            { title: 'Kündigungen' },
        ],
    },
});

const dates = reactive<Record<number, string>>(
    Object.fromEntries(
        props.cancellations.data.map((cancellation) => [
            cancellation.member_number,
            '',
        ]),
    ),
);
const form = useForm({ lock_version: 0, exit_date: '' });

function confirm(cancellation: Cancellation) {
    form.clearErrors();
    form.lock_version = cancellation.lock_version;
    form.exit_date = dates[cancellation.member_number] ?? '';
    form.patch(
        `/mitglieder/${cancellation.member_number}/kuendigung-bestaetigen`,
        { preserveScroll: true },
    );
}

const requestedDate = (value: string) =>
    new Intl.DateTimeFormat('de-DE').format(
        new Date(`${value.slice(0, 10)}T00:00:00`),
    );
</script>

<template>
    <Head title="Kündigungen" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <MemberPageHeader
            :total-members="totalMembers"
            :description="
                $address(
                    'Bearbeite die von Mitgliedern eingereichten Kündigungen.',
                    'Bearbeiten Sie die von Mitgliedern eingereichten Kündigungen.',
                )
            "
        />

        <MembersNav />

        <section class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold">
                    Offene Kündigungen ({{ cancellations.total }})
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Mit der Bestätigung wird das Austrittsdatum gespeichert und
                    eine Bestätigungsmail an das Mitglied versendet.
                </p>
            </div>

            <StatusAlert
                v-if="Object.keys(form.errors).length"
                type="error"
                title="Kündigung nicht bestätigt"
                :messages="Object.values(form.errors)"
            />

            <StatusAlert
                v-if="!cancellations.data.length"
                type="success"
                title="Keine offenen Kündigungen"
            >
                Derzeit liegen keine Kündigungen zur Bearbeitung vor.
            </StatusAlert>

            <article
                v-for="cancellation in cancellations.data"
                :key="cancellation.member_number"
                class="grid gap-4 rounded-xl border bg-card p-5 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end"
            >
                <div class="min-w-0 space-y-1">
                    <Link
                        :href="`/mitglieder/${cancellation.member_number}`"
                        class="font-medium underline"
                    >
                        {{ cancellation.first_name }}
                        {{ cancellation.last_name }} · Nr.
                        {{ cancellation.member_number }}
                    </Link>
                    <p class="text-sm break-words text-muted-foreground">
                        {{ cancellation.membership_type }} ·
                        {{ cancellation.email }}
                    </p>
                    <p class="text-sm">
                        Eingegangen:
                        {{ requestedDate(cancellation.requested_at) }}
                    </p>
                </div>

                <form
                    class="grid gap-3 sm:grid-cols-[minmax(12rem,1fr)_auto] sm:items-end lg:min-w-96"
                    @submit.prevent="confirm(cancellation)"
                >
                    <div class="min-w-0 space-y-2">
                        <Label
                            :for="`cancellation-date-${cancellation.member_number}`"
                            >Austrittsdatum</Label
                        >
                        <Input
                            :id="`cancellation-date-${cancellation.member_number}`"
                            v-model="dates[cancellation.member_number]"
                            type="date"
                            :min="today"
                            required
                            :disabled="form.processing"
                            class="min-w-0"
                        />
                    </div>
                    <Button
                        class="w-full sm:w-auto"
                        :disabled="form.processing"
                    >
                        Kündigung bestätigen
                    </Button>
                </form>
            </article>

            <div class="flex gap-4">
                <Link
                    v-if="cancellations.prev_page_url"
                    :href="cancellations.prev_page_url"
                    >Zurück</Link
                >
                <Link
                    v-if="cancellations.next_page_url"
                    :href="cancellations.next_page_url"
                    >Weiter</Link
                >
            </div>
        </section>
    </div>
</template>
