<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import SignaturePad from '@/components/SignaturePad.vue';
import Frame from '@/components/selfservice/Frame.vue';
import ProfileFields from '@/components/selfservice/ProfileFields.vue';
import { Button } from '@/components/ui/button';
const props = defineProps<{
    kind: 'application' | 'sepa';
    requiresApproval: boolean;
    email: string;
    member: Record<string, string | number | null> | null;
    texts: Record<string, string>;
    version: number;
    membershipOptions: Record<string, string>;
}>();
const application = props.kind === 'application';
const title = application ? 'Mitglied werden' : 'SEPA-Mandat anlegen';
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
        fields.map((key) => [
            key,
            String(props.member?.[key] ?? (key === 'country' ? 'DE' : '')),
        ]),
    ),
);
const form = useForm({
    version: props.version,
    lock_version: props.member?.lock_version ?? null,
    membership_type: '',
    accepted: false,
    signature: null as string | null,
    guardian_name: '',
    guardian_signature: null as string | null,
    iban: '',
    account_holder_first_name: String(props.member?.first_name ?? ''),
    account_holder_last_name: String(props.member?.last_name ?? ''),
    account_holder_street: String(props.member?.street ?? ''),
    account_holder_postal_code: String(props.member?.postal_code ?? ''),
    account_holder_city: String(props.member?.city ?? ''),
    account_holder_country: String(props.member?.country ?? 'DE'),
});
const minor = computed(() => {
    if (!profile.value.birth_date) return false;
    const birthday = new Date(profile.value.birth_date + 'T00:00:00');
    birthday.setFullYear(birthday.getFullYear() + 18);
    return birthday > new Date();
});
const bankFields = [
    { key: 'iban' as const, label: 'IBAN' },
    {
        key: 'account_holder_first_name' as const,
        label: 'Vorname Kontoinhaber',
    },
    {
        key: 'account_holder_last_name' as const,
        label: 'Nachname Kontoinhaber',
    },
    {
        key: 'account_holder_street' as const,
        label: 'Straße und Hausnummer Kontoinhaber',
    },
    {
        key: 'account_holder_postal_code' as const,
        label: 'Postleitzahl Kontoinhaber',
    },
    { key: 'account_holder_city' as const, label: 'Ort Kontoinhaber' },
    { key: 'account_holder_country' as const, label: 'Land Kontoinhaber' },
];
function submit() {
    form.transform((data) => ({
        ...data,
        ...(application ? profile.value : {}),
    })).post(`/selfservice/formulare/${props.kind}`);
}
</script>
<template>
    <Head :title="title" /><Frame signed-in
        ><Link v-if="member" href="/selfservice" class="text-sm underline"
            >Zurück zum Mitgliederbereich</Link
        >
        <h1 class="text-3xl font-semibold">{{ title }}</h1>
        <p>Bestätigte E-Mail-Adresse: {{ email }}</p>
        <p
            v-if="application && requiresApproval"
            role="status"
            class="rounded-md bg-muted p-4"
        >
            Dein unterschriebener Antrag wird von der Verwaltung geprüft. Die
            Mitgliedschaft beginnt erst mit der Freigabe.
        </p>
        <form class="space-y-6" @submit.prevent="submit">
            <section class="space-y-4 rounded-xl border p-5">
                <template v-if="application"
                    ><ProfileFields v-model="profile" /><label
                        class="block space-y-1"
                        ><span>Mitgliedschaft *</span
                        ><select
                            v-model="form.membership_type"
                            required
                            class="w-full rounded border bg-background p-2"
                        >
                            <option disabled value="">Bitte auswählen</option>
                            <option
                                v-for="(label, value) in membershipOptions"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option>
                        </select></label
                    ></template
                >
                <div v-else class="grid gap-4 sm:grid-cols-2">
                    <label
                        v-for="field in bankFields"
                        :key="field.key"
                        class="block space-y-1"
                        ><span>{{ field.label }} *</span
                        ><input
                            v-model="form[field.key]"
                            required
                            class="w-full rounded border bg-background p-2"
                    /></label>
                </div>
            </section>
            <section class="space-y-4 rounded-xl border p-5">
                <h2 class="text-xl font-medium">
                    {{
                        application
                            ? 'Beitrittserklärung'
                            : 'SEPA-Mandat für wiederkehrende Zahlungen'
                    }}
                </h2>
                <p class="whitespace-pre-wrap">
                    {{ texts[application ? 'application_text' : 'sepa_text'] }}
                </p>
                <label class="flex items-start gap-3"
                    ><input
                        v-model="form.accepted"
                        type="checkbox"
                        required
                        class="mt-1"
                    /><span
                        >Ich habe die Erklärung gelesen und stimme ihr zu.</span
                    ></label
                >
                <h3 class="font-medium">
                    {{
                        application
                            ? 'Unterschrift Mitglied'
                            : 'Unterschrift Kontoinhaber'
                    }}
                </h3>
                <SignaturePad v-model="form.signature" />
            </section>
            <section
                v-if="application && minor"
                class="space-y-4 rounded-xl border p-5"
            >
                <h2 class="text-xl font-medium">Zustimmung Sorgeberechtigte</h2>
                <p class="whitespace-pre-wrap">{{ texts.guardian_text }}</p>
                <label class="block space-y-1"
                    ><span>Name der sorgeberechtigten Person *</span
                    ><input
                        v-model="form.guardian_name"
                        required
                        class="w-full rounded border bg-background p-2" /></label
                ><SignaturePad v-model="form.guardian_signature" />
            </section>
            <p
                v-for="error in form.errors"
                :key="error"
                role="alert"
                class="text-destructive"
            >
                {{ error }}
            </p>
            <p class="text-sm text-muted-foreground">
                Mit der Abgabe werden deine Angaben, der bestätigte Text, die
                Unterschrift, Zeitpunkt und IP-Adresse im PDF festgehalten. Das
                Dokument kannst du anschließend herunterladen.
            </p>
            <Button :disabled="form.processing || !form.signature">{{
                application
                    ? requiresApproval
                        ? 'Beitritt verbindlich beantragen'
                        : 'Verbindlich beitreten'
                    : 'Mandat verbindlich erteilen'
            }}</Button>
        </form>
    </Frame>
</template>
