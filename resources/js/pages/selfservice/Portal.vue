<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Frame from '@/components/selfservice/Frame.vue';
import ProfileFields from '@/components/selfservice/ProfileFields.vue';
import { Button } from '@/components/ui/button';
const props = defineProps<{
    member: Record<string, string | number | null>;
    documents: string[];
    canJoin: boolean;
    pendingApplication: {
        membership_type: string;
        submitted_at: string;
    } | null;
}>();
const fields = [
    'first_name',
    'middle_name',
    'last_name',
    'birth_date',
    'mobile_phone',
    'street',
    'postal_code',
    'city',
    'country',
];
const profile = ref<Record<string, string>>(
    Object.fromEntries(
        fields.map((key) => [key, String(props.member[key] ?? '')]),
    ),
);
const form = useForm({ lock_version: props.member.lock_version });
const email = useForm({ email: '', purpose: 'email' });
const emailSent = ref(false);
function save() {
    form.transform((data) => ({
        ...data,
        ...profile.value,
        lock_version: props.member.lock_version,
    })).patch('/selfservice/profil');
}
</script>
<template>
    <Head title="Mein Mitgliederbereich" /><Frame signed-in
        ><h1 class="text-3xl font-semibold">Hallo {{ member.first_name }}</h1>
        <p>
            Nr. {{ member.member_number }} · {{ member.membership_type }} ·
            {{ member.email }}
        </p>
        <section class="space-y-4 rounded-xl border p-5">
            <h2 class="text-xl font-medium">Mitgliedschaft & Dokumente</h2>
            <p
                v-if="pendingApplication"
                role="status"
                class="rounded-md bg-muted p-3"
            >
                Dein Antrag für „{{ pendingApplication.membership_type }}“
                wartet auf Freigabe durch die Verwaltung. Deine Mitgliedschaft
                beginnt mit der Freigabe. Anschließend kannst du dein
                SEPA-Mandat anlegen.
            </p>
            <div class="flex flex-wrap gap-3">
                <Button v-if="canJoin" as-child
                    ><Link href="/selfservice/beitritt"
                        >Jetzt Mitglied werden</Link
                    ></Button
                ><Button
                    v-if="
                        member.joined_at &&
                        !member.left_at &&
                        !documents.includes('sepa')
                    "
                    as-child
                    variant="outline"
                    ><Link href="/selfservice/mandat"
                        >SEPA-Mandat digital anlegen</Link
                    ></Button
                ><a
                    v-for="kind in documents"
                    :key="kind"
                    :href="`/selfservice/dokumente/${kind}`"
                    class="rounded-md border px-4 py-2 text-sm underline"
                    >{{
                        kind === 'application'
                            ? 'Beitrittsformular'
                            : 'SEPA-Mandat'
                    }}
                    herunterladen</a
                >
            </div>
            <p v-if="!documents.length" class="text-sm text-muted-foreground">
                Es sind noch keine Dokumente hinterlegt.
            </p>
            <p class="text-sm text-muted-foreground">
                Änderungen an vorhandenen Mandaten oder an deiner Mitgliedschaft
                übernimmt die Verwaltung.
            </p>
        </section>
        <form class="space-y-4 rounded-xl border p-5" @submit.prevent="save">
            <h2 class="text-xl font-medium">Persönliche Daten</h2>
            <ProfileFields v-model="profile" />
            <p
                v-for="error in form.errors"
                :key="error"
                role="alert"
                class="text-destructive"
            >
                {{ error }}
            </p>
            <Button :disabled="form.processing">Daten speichern</Button>
        </form>
        <form
            class="space-y-4 rounded-xl border p-5"
            @submit.prevent="
                email.post('/selfservice/zugang/anfordern', {
                    onSuccess: () => (emailSent = true),
                })
            "
        >
            <h2 class="text-xl font-medium">E-Mail-Adresse ändern</h2>
            <p class="text-sm">
                Die bisherige Adresse bleibt gültig, bis du die neue Adresse
                bestätigst. Öffne den Link im selben Browser, während dein
                Zugang noch aktiv ist.
            </p>
            <label class="block space-y-1"
                ><span>Neue E-Mail-Adresse</span
                ><input
                    v-model="email.email"
                    type="email"
                    required
                    class="w-full rounded border bg-background p-2"
            /></label>
            <p
                v-for="error in email.errors"
                :key="error"
                role="alert"
                class="text-destructive"
            >
                {{ error }}
            </p>
            <Button variant="outline" :disabled="email.processing"
                >Bestätigungslink senden</Button
            >
            <p v-if="emailSent" role="status">
                Wenn die Adresse verwendet werden kann, erhältst du dort einen
                Bestätigungslink.
            </p>
        </form>
    </Frame>
</template>
