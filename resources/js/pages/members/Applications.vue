<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { reactive } from 'vue';
import MemberPageHeader from '@/components/members/MemberPageHeader.vue';
import MembersNav from '@/components/members/MembersNav.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
type Application = {
    member_number: number;
    first_name: string;
    last_name: string;
    membership_type: string;
    submitted_at: string;
    lock_version: number;
};
const props = defineProps<{
    totalMembers: number;
    today: string;
    applications: {
        data: Application[];
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Mitglieder', href: '/mitglieder' },
            { title: 'Beitrittsanträge' },
        ],
    },
});
const dates = reactive<Record<number, string>>(
    Object.fromEntries(
        props.applications.data.map((application) => [
            application.member_number,
            props.today,
        ]),
    ),
);
const form = useForm({ lock_version: 0, joined_at: '' });
function approve(application: Application) {
    form.clearErrors();
    form.lock_version = application.lock_version;
    form.joined_at = dates[application.member_number] ?? '';
    form.post(`/mitglieder/${application.member_number}/beitritt-freigeben`, {
        preserveScroll: true,
    });
}
</script>
<template>
    <Head title="Beitrittsanträge" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <MemberPageHeader
            :total-members="totalMembers"
            :description="
                $address(
                    'Prüfe offene Beitrittsanträge und gib Mitgliedschaften frei.',
                    'Prüfen Sie offene Beitrittsanträge und geben Sie Mitgliedschaften frei.',
                )
            "
        />
        <MembersNav />
        <section class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold">
                    Offene Beitrittsanträge ({{ applications.total }})
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ $address('Lege', 'Legen Sie') }} das Eintrittsdatum fest
                    und {{ $address('gib', 'geben Sie') }} anschließend die
                    Mitgliedschaft frei. Das Datum darf in der Vergangenheit
                    oder Zukunft liegen.
                </p>
            </div>
            <StatusAlert
                v-if="Object.keys(form.errors).length"
                type="error"
                title="Beitritt nicht freigegeben"
                :messages="Object.values(form.errors)"
            />
            <StatusAlert
                v-if="!applications.data.length"
                type="success"
                title="Keine offenen Beitrittsanträge"
            >
                Derzeit liegen keine Beitrittsanträge zur Bearbeitung vor.
            </StatusAlert>
            <article
                v-for="application in applications.data"
                :key="application.member_number"
                class="grid gap-4 rounded-xl border bg-card p-5 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end"
            >
                <div class="min-w-0 space-y-1">
                    <Link
                        :href="`/mitglieder/${application.member_number}`"
                        class="font-medium underline"
                        >{{ application.first_name }}
                        {{ application.last_name }} · Nr.
                        {{ application.member_number }}</Link
                    >
                    <p class="text-sm text-muted-foreground">
                        {{ application.membership_type }} · Eingegangen:
                        {{
                            new Date(
                                application.submitted_at,
                            ).toLocaleDateString('de-DE')
                        }}
                    </p>
                </div>
                <div
                    class="flex flex-col gap-3 sm:flex-row sm:items-end lg:min-w-[32rem]"
                >
                    <Button as-child variant="outline">
                        <a
                            :href="`/mitglieder/${application.member_number}/dokumente/application`"
                            >Antrag herunterladen</a
                        >
                    </Button>
                    <form
                        class="grid min-w-0 flex-1 gap-3 sm:grid-cols-[minmax(12rem,1fr)_auto] sm:items-end"
                        @submit.prevent="approve(application)"
                    >
                        <div class="min-w-0 space-y-2">
                            <Label
                                :for="`joined-at-${application.member_number}`"
                                >Eintrittsdatum</Label
                            >
                            <Input
                                :id="`joined-at-${application.member_number}`"
                                v-model="dates[application.member_number]"
                                type="date"
                                required
                                :disabled="form.processing"
                            />
                        </div>
                        <Button
                            class="w-full sm:w-auto"
                            :disabled="form.processing"
                        >
                            Beitritt freigeben
                        </Button>
                    </form>
                </div>
            </article>
            <div class="flex gap-4">
                <Link
                    v-if="applications.prev_page_url"
                    :href="applications.prev_page_url"
                    >Zurück</Link
                ><Link
                    v-if="applications.next_page_url"
                    :href="applications.next_page_url"
                    >Weiter</Link
                >
            </div>
        </section>
    </div>
</template>
