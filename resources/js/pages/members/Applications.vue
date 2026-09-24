<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import MembersNav from '@/components/members/MembersNav.vue';
import { Button } from '@/components/ui/button';
type Application = {
    member_number: number;
    first_name: string;
    last_name: string;
    membership_type: string;
    submitted_at: string;
    lock_version: number;
};
defineProps<{
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
const form = useForm({ lock_version: 0 });
function approve(application: Application) {
    form.lock_version = application.lock_version;
    form.post(`/mitglieder/${application.member_number}/beitritt-freigeben`, {
        preserveScroll: true,
    });
}
</script>
<template>
    <Head title="Beitrittsanträge" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Mitglieder</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Prüfe offene Beitrittsanträge und gib Mitgliedschaften frei.
            </p>
        </header>
        <MembersNav />
        <section class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold">
                    Offene Beitrittsanträge ({{ applications.total }})
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Mit der Freigabe beginnt die Mitgliedschaft am heutigen Tag.
                </p>
            </div>
            <p
                v-for="error in form.errors"
                :key="error"
                role="alert"
                class="text-destructive"
            >
                {{ error }}
            </p>
            <p v-if="!applications.data.length">
                Keine offenen Beitrittsanträge.
            </p>
            <article
                v-for="application in applications.data"
                :key="application.member_number"
                class="flex flex-wrap items-center justify-between gap-4 rounded-xl border bg-card p-5"
            >
                <div class="space-y-1">
                    <Link
                        :href="`/mitglieder/${application.member_number}`"
                        class="font-medium underline"
                        >{{ application.first_name }}
                        {{ application.last_name }} · Nr.
                        {{ application.member_number }}</Link
                    >
                    <p>
                        {{ application.membership_type }} · Eingegangen:
                        {{
                            new Date(
                                application.submitted_at,
                            ).toLocaleDateString('de-DE')
                        }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a
                        :href="`/mitglieder/${application.member_number}/dokumente/application`"
                        class="rounded-md border px-4 py-2 text-sm"
                        >Antrag herunterladen</a
                    ><Button
                        :disabled="form.processing"
                        @click="approve(application)"
                        >Beitritt freigeben</Button
                    >
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
