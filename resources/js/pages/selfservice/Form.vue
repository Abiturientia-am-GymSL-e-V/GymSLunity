<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import SignaturePad from '@/components/SignaturePad.vue';
import CountryInput from '@/components/CountryInput.vue';
import IbanInput from '@/components/IbanInput.vue';
import Frame from '@/components/selfservice/Frame.vue';
import ProfileFields from '@/components/selfservice/ProfileFields.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
const props = defineProps<{
    kind: 'application' | 'sepa';
    requiresApproval: boolean;
    email: string;
    member: Record<string, string | number | null> | null;
    texts: Record<string, string>;
    version: number;
    membershipOptions: Record<string, string>;
    paymentOptions: Record<string, string>;
    genderOptions: Record<string, string>;
    hasActiveMandate: boolean;
}>();
const page = usePage();
const application = props.kind === 'application';
const title = application
    ? 'Mitglied werden'
    : props.hasActiveMandate
      ? 'SEPA-Mandat ändern'
      : 'SEPA-Mandat anlegen';
const fields = [
    'first_name',
    'middle_name',
    'last_name',
    'gender',
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
    sponsor_contribution: '',
    payment_method: '',
    accepted: false,
    signature: null as string | null,
    guardian_name: '',
    guardian_signature: null as string | null,
    mandate_accepted: false,
    mandate_signature: null as string | null,
    iban: String(props.member?.iban ?? ''),
    account_holder_first_name: String(
        props.member?.account_holder_first_name ??
            props.member?.first_name ??
            '',
    ),
    account_holder_last_name: String(
        props.member?.account_holder_last_name ?? props.member?.last_name ?? '',
    ),
    account_holder_street: String(
        props.member?.account_holder_street ?? props.member?.street ?? '',
    ),
    account_holder_postal_code: String(
        props.member?.account_holder_postal_code ??
            props.member?.postal_code ??
            '',
    ),
    account_holder_city: String(
        props.member?.account_holder_city ?? props.member?.city ?? '',
    ),
    account_holder_country: String(
        props.member?.account_holder_country ?? props.member?.country ?? 'DE',
    ),
});
const minor = computed(() => {
    if (!profile.value.birth_date) return false;
    const birthday = new Date(profile.value.birth_date + 'T00:00:00');
    birthday.setFullYear(birthday.getFullYear() + 18);
    return birthday > new Date();
});
const sponsorMembership = computed(() =>
    form.membership_type.toLocaleLowerCase('de').includes('förder'),
);
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
        <StatusAlert
            v-if="application && requiresApproval"
            type="info"
            title="Freigabe erforderlich"
        >
            {{ $address('Dein', 'Ihr') }} unterschriebener Antrag wird vom
            Vorstand geprüft. Die Mitgliedschaft beginnt erst mit der Freigabe.
        </StatusAlert>
        <StatusAlert
            v-if="!application && hasActiveMandate"
            type="warning"
            title="Neues Mandat ersetzt das bisherige"
        >
            Mit dem Absenden werden die neuen Kontodaten und
            {{ $address('deine', 'Ihre') }} neue Unterschrift als eigenes Mandat
            gespeichert. Das bisherige Mandat wird widerrufen und bleibt nur in
            der internen Mandatshistorie erhalten.
        </StatusAlert>
        <form class="space-y-6" @submit.prevent="submit">
            <section class="space-y-4 rounded-xl border p-5">
                <template v-if="application"
                    ><ProfileFields
                        v-model="profile"
                        :email="email"
                        :default-country="
                            String(page.props.defaultCountry ?? 'DE')
                        "
                        :gender-options="genderOptions"
                    />
                    <div class="space-y-2">
                        <Label for="application-membership-type"
                            >Mitgliedschaft *</Label
                        >
                        <select
                            id="application-membership-type"
                            v-model="form.membership_type"
                            required
                            class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                        >
                            <option disabled value="">Bitte auswählen</option>
                            <option
                                v-for="(label, value) in membershipOptions"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option>
                        </select>
                    </div>
                    <div v-if="sponsorMembership" class="space-y-2">
                        <Label for="application-sponsor-contribution"
                            >Förderbetrag pro Jahr (€) *</Label
                        >
                        <Input
                            id="application-sponsor-contribution"
                            v-model="form.sponsor_contribution"
                            type="number"
                            min="0.01"
                            max="999.99"
                            step="0.01"
                            required
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="application-payment-method"
                            >Zahlungsart *</Label
                        >
                        <select
                            id="application-payment-method"
                            v-model="form.payment_method"
                            required
                            class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                        >
                            <option disabled value="">Bitte auswählen</option>
                            <option
                                v-for="(label, value) in paymentOptions"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option>
                        </select>
                    </div>
                </template>
                <div v-else class="grid gap-5 sm:grid-cols-2">
                    <div
                        v-for="field in bankFields"
                        :key="field.key"
                        class="space-y-2"
                    >
                        <Label :for="`standalone-${field.key}`"
                            >{{ field.label }} *</Label
                        >
                        <IbanInput
                            v-if="field.key === 'iban'"
                            :id="`standalone-${field.key}`"
                            v-model="form[field.key]"
                            required
                        />
                        <Input
                            v-else
                            :id="`standalone-${field.key}`"
                            v-model="form[field.key]"
                            required
                        />
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="standalone-account-holder-country"
                            >Land Kontoinhaber *</Label
                        >
                        <CountryInput
                            id="standalone-account-holder-country"
                            v-model="form.account_holder_country"
                            required
                        />
                    </div>
                </div>
            </section>
            <section
                v-if="application && form.payment_method === 'SEPA-Lastschrift'"
                class="space-y-5 rounded-xl border p-5"
            >
                <div>
                    <h2 class="text-xl font-medium">SEPA-Lastschriftmandat</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Für die gewählte Zahlungsart ist ein vollständiges
                        Mandat erforderlich.
                    </p>
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div
                        v-for="field in bankFields"
                        :key="field.key"
                        class="space-y-2"
                    >
                        <Label :for="`mandate-${field.key}`"
                            >{{ field.label }} *</Label
                        >
                        <IbanInput
                            v-if="field.key === 'iban'"
                            :id="`mandate-${field.key}`"
                            v-model="form[field.key]"
                            required
                        />
                        <Input
                            v-else
                            :id="`mandate-${field.key}`"
                            v-model="form[field.key]"
                            required
                        />
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="account-holder-country"
                            >Land Kontoinhaber *</Label
                        >
                        <CountryInput
                            id="account-holder-country"
                            v-model="form.account_holder_country"
                            required
                        />
                    </div>
                </div>
                <p class="whitespace-pre-wrap">{{ texts.sepa_text }}</p>
                <label class="flex items-start gap-3 text-sm leading-5"
                    ><input
                        v-model="form.mandate_accepted"
                        type="checkbox"
                        required
                        class="mt-1"
                    /><span
                        >Ich erteile das SEPA-Lastschriftmandat
                        verbindlich.</span
                    ></label
                >
                <h3 class="text-sm font-medium">Unterschrift Kontoinhaber</h3>
                <SignaturePad v-model="form.mandate_signature" />
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
                <label class="flex items-start gap-3 text-sm leading-5"
                    ><input
                        v-model="form.accepted"
                        type="checkbox"
                        required
                        class="mt-1"
                    /><span
                        >Ich habe die Erklärung gelesen und stimme ihr zu.</span
                    ></label
                >
                <h3 class="text-sm font-medium">
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
                <div class="space-y-2">
                    <Label for="guardian-name"
                        >Name der sorgeberechtigten Person *</Label
                    >
                    <Input
                        id="guardian-name"
                        v-model="form.guardian_name"
                        required
                    />
                </div>
                <h3 class="text-sm font-medium">
                    Unterschrift sorgeberechtigte Person
                </h3>
                <SignaturePad v-model="form.guardian_signature" />
            </section>
            <StatusAlert
                v-if="Object.keys(form.errors).length"
                type="error"
                title="Formular nicht übermittelt"
                :messages="Object.values(form.errors)"
            />
            <p class="text-sm text-muted-foreground">
                Mit der Abgabe werden {{ $address('deine', 'Ihre') }} Angaben,
                der bestätigte Text, die Unterschrift, Zeitpunkt und IP-Adresse
                im PDF festgehalten. Das Dokument
                {{ $address('kannst du', 'können Sie') }} anschließend
                herunterladen.
            </p>
            <Button
                :disabled="
                    form.processing ||
                    !form.signature ||
                    (application &&
                        form.payment_method === 'SEPA-Lastschrift' &&
                        !form.mandate_signature)
                "
                >{{
                    application
                        ? requiresApproval
                            ? 'Beitritt verbindlich beantragen'
                            : 'Verbindlich beitreten'
                        : hasActiveMandate
                          ? 'Neues Mandat verbindlich erteilen'
                          : 'Mandat verbindlich erteilen'
                }}</Button
            >
        </form>
    </Frame>
</template>
