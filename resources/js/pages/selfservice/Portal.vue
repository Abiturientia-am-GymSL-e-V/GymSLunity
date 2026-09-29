<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    CalendarRange,
    CircleAlert,
    CircleCheck,
    HandCoins,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import MemberFieldControl from '@/components/members/MemberFieldControl.vue';
import EmailChangeForm from '@/components/selfservice/EmailChangeForm.vue';
import Frame from '@/components/selfservice/Frame.vue';
import PortalPasskeys from '@/components/selfservice/PortalPasskeys.vue';
import type { PortalPasskey } from '@/components/selfservice/PortalPasskeys.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { formatDate, formatMoney } from '@/lib/format';
import { formatIban } from '@/lib/formatIban';
import { renew as renewCalendarLinkRoute } from '@/routes/selfservice/calendar-link';
import { receipt as transactionReceipt } from '@/routes/selfservice/transactions';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import type { MemberSection, MemberValue } from '@/types/members';

type GiroCode = {
    amount: string;
    recipient: string;
    iban: string;
    bic: string;
    purpose: string;
    image: string;
};
type ContributionEntry = {
    id: number;
    amount_cents: number;
    booking_date: string;
    description: string;
    reference: string | null;
};
const props = defineProps<{
    member: Record<string, MemberValue>;
    profileSections: MemberSection[];
    documents: string[];
    canJoin: boolean;
    isActiveMember: boolean;
    pendingApplication: {
        membership_type: string;
        submitted_at: string;
    } | null;
    canRequestCancellation: boolean;
    pendingCancellation: {
        requested_at: string;
    } | null;
    calendarEnabled: boolean;
    bookingsEnabled: boolean;
    calendarSubscription: {
        url: string;
        calendars: { name: string; color: string }[];
    } | null;
    contributionAccount: {
        balance_cents: number;
        transactions: ContributionEntry[];
        giroCode: GiroCode | null;
    } | null;
    passkeys: PortalPasskey[];
}>();

const profileFields = computed(() =>
    props.profileSections.flatMap((section) => section.fields),
);
const editableFields = computed(() =>
    profileFields.value.filter((field) => !field.readOnly),
);
const values = ref<Record<string, MemberValue>>(
    Object.fromEntries(
        profileFields.value.map((field) => [
            field.key,
            props.member[field.key] ?? null,
        ]),
    ),
);
const form = useForm<Record<string, MemberValue>>({
    lock_version: Number(props.member.lock_version),
});
const profileSaved = ref(false);
const cancelOpen = ref(false);
const cancelForm = useForm<{
    lock_version: number;
    cancellation?: string;
}>({
    lock_version: Number(props.member.lock_version),
});
const withdrawalForm = useForm<{
    lock_version: number;
    cancellation?: string;
}>({
    lock_version: Number(props.member.lock_version),
});
const sponsorMembership = computed(() =>
    String(values.value.membership_type ?? props.member.membership_type ?? '')
        .toLocaleLowerCase('de')
        .includes('förder'),
);
const revokesMandate = computed(
    () =>
        props.member.payment_method === 'SEPA-Lastschrift' &&
        values.value.payment_method !== undefined &&
        values.value.payment_method !== 'SEPA-Lastschrift',
);

function visibleField(key: string) {
    return key !== 'sponsor_contribution' || sponsorMembership.value;
}
function save() {
    profileSaved.value = false;
    const editableValues = Object.fromEntries(
        editableFields.value.map((field) => [
            field.key,
            values.value[field.key],
        ]),
    );
    form.transform((data) => ({
        ...data,
        ...editableValues,
        lock_version: props.member.lock_version,
    })).patch('/selfservice/profil', {
        preserveScroll: true,
        onSuccess: () => (profileSaved.value = true),
        onError: () =>
            toast.error('Die Änderungen konnten nicht gespeichert werden.'),
    });
}
function cancelMembership() {
    cancelForm
        .transform((data) => ({
            ...data,
            lock_version: props.member.lock_version,
        }))
        .patch('/selfservice/mitgliedschaft/kuendigen', {
            preserveScroll: true,
            onSuccess: () => (cancelOpen.value = false),
            onError: () =>
                toast.error('Die Kündigung konnte nicht übermittelt werden.'),
        });
}
function withdrawCancellation() {
    withdrawalForm
        .transform((data) => ({
            ...data,
            lock_version: props.member.lock_version,
        }))
        .delete('/selfservice/mitgliedschaft/kuendigen', {
            preserveScroll: true,
            onError: () =>
                toast.error(
                    'Die Kündigung konnte nicht zurückgenommen werden.',
                ),
        });
}
function copyCalendarLink(value: string) {
    void window.navigator.clipboard
        .writeText(value)
        .then(() => toast.success('Kalenderlink kopiert.'));
}
function renewCalendarLink() {
    router.post(renewCalendarLinkRoute(), {}, { preserveScroll: true });
}
function subscriptionLink(value: string) {
    return value.replace(/^https?:/, 'webcal:');
}
</script>

<template>
    <Head title="Mein Mitgliederbereich" />
    <Frame signed-in>
        <h1 class="text-3xl font-semibold">Hallo {{ member.first_name }}</h1>
        <p>
            Nr. {{ member.member_number }} · {{ member.membership_type }} ·
            {{ member.email }}
        </p>

        <section class="space-y-4 rounded-xl border p-5">
            <h2 class="text-xl font-medium">Mitgliedschaft & Dokumente</h2>
            <Alert v-if="pendingApplication" variant="info">
                <CircleAlert />
                <AlertTitle>Antrag wird geprüft</AlertTitle>
                <AlertDescription>
                    {{ $address('Dein', 'Ihr') }} Antrag für „{{
                        pendingApplication.membership_type
                    }}“ wartet auf Freigabe durch den Vorstand.
                    {{ $address('Deine', 'Ihre') }}
                    Mitgliedschaft beginnt mit der Freigabe.
                </AlertDescription>
            </Alert>
            <Alert v-else-if="pendingCancellation" variant="info">
                <CircleAlert />
                <AlertTitle>Kündigung wird bearbeitet</AlertTitle>
                <AlertDescription>
                    {{ $address('Deine', 'Ihre') }} Kündigung ist am
                    {{
                        formatDate(
                            pendingCancellation.requested_at.slice(0, 10),
                        )
                    }}
                    beim Vorstand eingegangen. Nach der Bearbeitung
                    {{ $address('erhältst du', 'erhalten Sie') }} eine
                    Bestätigung mit dem Austrittsdatum per E-Mail.
                    <span class="mt-3 block">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            :disabled="withdrawalForm.processing"
                            @click="withdrawCancellation"
                        >
                            Kündigung zurücknehmen
                        </Button>
                    </span>
                </AlertDescription>
            </Alert>
            <Alert
                v-else-if="isActiveMember && member.left_at"
                variant="warning"
            >
                <CircleCheck />
                <AlertTitle>Kündigung gespeichert</AlertTitle>
                <AlertDescription>
                    Als Austrittsdatum ist der
                    {{ formatDate(String(member.left_at)) }} hinterlegt.
                    <span class="mt-3 block">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            :disabled="withdrawalForm.processing"
                            @click="withdrawCancellation"
                        >
                            Kündigung zurücknehmen
                        </Button>
                    </span>
                </AlertDescription>
            </Alert>
            <div class="flex flex-wrap gap-3">
                <Button v-if="canJoin" as-child>
                    <Link href="/selfservice/beitritt">
                        {{
                            member.left_at
                                ? 'Neuen Mitgliedsantrag stellen'
                                : 'Jetzt Mitglied werden'
                        }}
                    </Link>
                </Button>
                <Button v-if="isActiveMember" as-child variant="outline">
                    <Link href="/selfservice/mandat">
                        {{
                            member.payment_method === 'SEPA-Lastschrift'
                                ? documents.includes('sepa')
                                    ? 'Kontodaten ändern / neues Mandat'
                                    : 'SEPA-Mandat nachreichen'
                                : 'SEPA-Lastschrift einrichten'
                        }}
                    </Link>
                </Button>
                <Button
                    v-if="canRequestCancellation"
                    type="button"
                    variant="outline"
                    @click="cancelOpen = true"
                >
                    Mitgliedschaft kündigen
                </Button>
                <a
                    v-for="kind in documents"
                    :key="kind"
                    :href="`/selfservice/dokumente/${kind}`"
                    class="rounded-md border px-4 py-2 text-sm underline"
                >
                    {{
                        kind === 'application'
                            ? 'Beitrittsformular'
                            : 'SEPA-Mandat'
                    }}
                    herunterladen
                </a>
            </div>
            <p v-if="!documents.length" class="text-sm text-muted-foreground">
                Es sind noch keine Dokumente hinterlegt.
            </p>
        </section>

        <section
            v-if="bookingsEnabled && isActiveMember"
            class="space-y-4 rounded-xl border p-5"
        >
            <div class="flex items-center gap-2">
                <CalendarRange class="size-5 text-muted-foreground" />
                <h2 class="text-xl font-medium">Ressourcen buchen</h2>
            </div>
            <p class="text-sm text-muted-foreground">
                Frage verfügbare Räume, Geräte und weitere Vereinsressourcen an
                oder
                {{ $address('verwalte deine', 'verwalten Sie Ihre') }}
                bestehenden Buchungen.
            </p>
            <Button as-child>
                <Link href="/selfservice/buchungen">Zu meinen Buchungen</Link>
            </Button>
        </section>

        <section
            v-if="contributionAccount"
            class="space-y-4 rounded-xl border p-5"
        >
            <div class="flex items-center gap-2">
                <HandCoins class="size-5 text-muted-foreground" />
                <h2 class="text-xl font-medium">Beitragskonto</h2>
            </div>
            <div>
                <p class="text-sm text-muted-foreground">Aktueller Saldo</p>
                <p
                    class="text-2xl font-semibold tabular-nums"
                    :class="{
                        'text-amber-700 dark:text-amber-300':
                            contributionAccount.balance_cents > 0,
                        'text-emerald-700 dark:text-emerald-300':
                            contributionAccount.balance_cents < 0,
                    }"
                >
                    {{ formatMoney(contributionAccount.balance_cents) }}
                </p>
                <p class="text-xs text-muted-foreground">
                    Positive Beträge sind noch offen.
                </p>
            </div>
            <Dialog v-if="contributionAccount.giroCode">
                <DialogTrigger as-child>
                    <Button>Girocode für Überweisung anzeigen</Button>
                </DialogTrigger>
                <DialogContent class="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>Offenen Betrag überweisen</DialogTitle>
                        <DialogDescription>
                            {{ $address('Scanne', 'Scannen Sie') }} den Girocode
                            mit {{ $address('deiner', 'Ihrer') }} Banking-App.
                            {{ $address('Prüfe', 'Prüfen Sie') }}
                            die Angaben vor der Freigabe der Überweisung.
                        </DialogDescription>
                    </DialogHeader>
                    <img
                        :src="contributionAccount.giroCode.image"
                        alt="Girocode für den offenen Mitgliedsbeitrag"
                        class="mx-auto size-72 max-w-full rounded-lg border bg-white p-3"
                    />
                    <dl class="grid gap-2 text-sm sm:grid-cols-[9rem_1fr]">
                        <dt class="text-muted-foreground">Empfänger</dt>
                        <dd>{{ contributionAccount.giroCode.recipient }}</dd>
                        <dt class="text-muted-foreground">IBAN</dt>
                        <dd class="break-all">
                            {{ formatIban(contributionAccount.giroCode.iban) }}
                        </dd>
                        <dt
                            v-if="contributionAccount.giroCode.bic"
                            class="text-muted-foreground"
                        >
                            BIC
                        </dt>
                        <dd v-if="contributionAccount.giroCode.bic">
                            {{ contributionAccount.giroCode.bic }}
                        </dd>
                        <dt class="text-muted-foreground">Betrag</dt>
                        <dd>{{ contributionAccount.giroCode.amount }} €</dd>
                        <dt class="text-muted-foreground">Verwendungszweck</dt>
                        <dd>{{ contributionAccount.giroCode.purpose }}</dd>
                    </dl>
                </DialogContent>
            </Dialog>
            <p
                v-if="!contributionAccount.transactions.length"
                class="text-sm text-muted-foreground"
            >
                Noch keine Beitragsbuchungen.
            </p>
            <ol v-else class="divide-y rounded-lg border">
                <li
                    v-for="entry in contributionAccount.transactions"
                    :key="entry.id"
                    class="p-3"
                >
                    <div class="flex justify-between gap-3 text-sm">
                        <span>{{ entry.description }}</span>
                        <span class="shrink-0 font-medium tabular-nums">{{
                            formatMoney(entry.amount_cents)
                        }}</span>
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ formatDate(entry.booking_date)
                        }}<template v-if="entry.reference">
                            · {{ entry.reference }}</template
                        >
                        ·
                        <a
                            :href="transactionReceipt.url(entry.id)"
                            class="underline underline-offset-2 hover:text-foreground"
                            >Beleg herunterladen</a
                        >
                    </p>
                </li>
            </ol>
        </section>

        <PortalPasskeys :passkeys="passkeys" />

        <section v-if="calendarEnabled" class="space-y-4 rounded-xl border p-5">
            <div>
                <h2 class="text-xl font-medium">Meine Vereinskalender</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ $address('Dein', 'Ihr') }} persönlicher Link bündelt alle
                    für {{ $address('dich', 'Sie') }} freigegebenen Kalender und
                    aktualisiert sich automatisch.
                </p>
            </div>
            <Alert v-if="!calendarSubscription" variant="info">
                <CircleAlert />
                <AlertTitle>Noch keine Kalenderfreigabe</AlertTitle>
                <AlertDescription>
                    Aktuell ist für
                    {{ $address('deine', 'Ihre') }} Mitgliedsdaten kein
                    Vereinskalender freigegeben.
                </AlertDescription>
            </Alert>
            <template v-else>
                <ul class="flex flex-wrap gap-2">
                    <li
                        v-for="calendar in calendarSubscription.calendars"
                        :key="calendar.name"
                        class="flex items-center gap-2 rounded-full border px-3 py-1 text-sm"
                    >
                        <span
                            class="size-2.5 rounded-full"
                            :style="{ backgroundColor: calendar.color }"
                        ></span>
                        {{ calendar.name }}
                    </li>
                </ul>
                <div class="flex flex-wrap gap-3">
                    <Button as-child>
                        <a :href="subscriptionLink(calendarSubscription.url)"
                            >Kalender abonnieren</a
                        >
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        @click="copyCalendarLink(calendarSubscription.url)"
                    >
                        Link kopieren
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="renewCalendarLink"
                    >
                        Link erneuern
                    </Button>
                </div>
                <p class="text-xs text-muted-foreground">
                    Der Link ist persönlich. Bitte
                    {{ $address('gib', 'geben Sie') }} ihn nicht an andere
                    Personen weiter. Falls der Link bekannt geworden ist,
                    {{ $address('kannst du', 'können Sie') }} ihn erneuern; der
                    alte Link funktioniert danach nicht mehr.
                </p>
            </template>
        </section>

        <form
            v-if="profileSections.length"
            class="space-y-5 rounded-xl border p-5"
            @submit.prevent="save"
        >
            <div>
                <h2 class="text-xl font-medium">Meine Angaben</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Der Verein legt fest, welche Angaben
                    {{
                        $address(
                            'du sehen und welche du selbst ändern kannst',
                            'Sie sehen und welche Sie selbst ändern können',
                        )
                    }}.
                </p>
            </div>
            <Alert v-if="profileSaved" variant="success">
                <CircleCheck />
                <AlertTitle>Änderungen gespeichert</AlertTitle>
                <AlertDescription
                    >{{ $address('Deine', 'Ihre') }} Angaben wurden erfolgreich
                    aktualisiert.</AlertDescription
                >
            </Alert>
            <Alert v-if="Object.keys(form.errors).length" variant="destructive">
                <CircleAlert />
                <AlertTitle>Änderungen nicht gespeichert</AlertTitle>
                <AlertDescription
                    >Bitte {{ $address('prüfe', 'prüfen Sie') }} die markierten
                    Eingaben.</AlertDescription
                >
            </Alert>
            <Alert v-if="revokesMandate" variant="warning">
                <CircleAlert />
                <AlertTitle>SEPA-Mandat wird widerrufen</AlertTitle>
                <AlertDescription>
                    Beim Speichern wird {{ $address('dein', 'Ihr') }} aktuelles
                    SEPA-Mandat widerrufen. Das Mandat kann anschließend nicht
                    mehr im Mitgliederportal heruntergeladen werden. Der
                    Vorstand bewahrt es in der Mandatshistorie auf.
                </AlertDescription>
            </Alert>
            <section
                v-for="section in profileSections"
                :key="section.key"
                class="space-y-3"
            >
                <h3 class="font-medium">{{ section.title }}</h3>
                <div class="grid gap-5 sm:grid-cols-2">
                    <template v-for="field in section.fields" :key="field.key">
                        <div v-if="visibleField(field.key)" class="space-y-2">
                            <Label :for="`member-${field.key}`"
                                >{{ field.label
                                }}{{
                                    field.required && !field.readOnly
                                        ? ' *'
                                        : ''
                                }}<span
                                    v-if="field.readOnly"
                                    class="ml-1 font-normal text-muted-foreground"
                                    >(nur Anzeige)</span
                                ></Label
                            >
                            <MemberFieldControl
                                :field="field"
                                :value="values[field.key] ?? null"
                                :values="values"
                                :disabled="form.processing || field.readOnly"
                                :error="form.errors[field.key]"
                                @change="values[field.key] = $event"
                            />
                            <span
                                v-if="form.errors[field.key]"
                                :id="`error-${field.key}`"
                                class="text-sm text-destructive"
                            >
                                {{ form.errors[field.key] }}
                            </span>
                        </div>
                    </template>
                </div>
            </section>
            <Button v-if="editableFields.length" :disabled="form.processing"
                >Änderungen speichern</Button
            >
        </form>

        <EmailChangeForm />

        <Dialog v-model:open="cancelOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Kündigung anfragen</DialogTitle>
                    <DialogDescription>
                        {{ $address('Deine', 'Ihre') }} Kündigung wird an den
                        Vorstand übermittelt. Dort wird das Austrittsdatum
                        festgelegt und anschließend per E-Mail bestätigt.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="cancelMembership">
                    <Alert
                        v-if="
                            cancelForm.errors.cancellation ||
                            cancelForm.errors.lock_version
                        "
                        variant="destructive"
                    >
                        <CircleAlert />
                        <AlertTitle>Kündigung nicht übermittelt</AlertTitle>
                        <AlertDescription>{{
                            cancelForm.errors.cancellation ||
                            cancelForm.errors.lock_version
                        }}</AlertDescription>
                    </Alert>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="cancelOpen = false"
                            >Abbrechen</Button
                        >
                        <Button
                            variant="destructive"
                            :disabled="cancelForm.processing"
                            >Kündigung verbindlich anfragen</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </Frame>
</template>
