<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Ban, BookOpen, FileClock, FilePlus2, Settings2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import DonationCreateForm from '@/components/donations/DonationCreateForm.vue';
import DonationLedger from '@/components/donations/DonationLedger.vue';
import OpenCertificates from '@/components/donations/OpenCertificates.vue';
import InputError from '@/components/InputError.vue';
import SignaturePad from '@/components/SignaturePad.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatMoney } from '@/lib/format';
import type {
    Certificate,
    Donation,
    DonationConfiguration,
} from '@/types/donations';

const props = defineProps<{
    activeTab: 'ledger' | 'create' | 'open';
    donations: Donation[];
    openDonations: Donation[];
    purposes: Array<{ value: string; label: string }>;
    configuration: DonationConfiguration;
    hasProfileSignature: boolean;
    summary: {
        count: number;
        amount_cents: number;
        open_count: number;
        issued_count: number;
        revoked_count: number;
    };
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Spenden', href: '/spenden' }] },
});

const tabs = [
    ['ledger', 'Spendenbuch', BookOpen, '/spenden'],
    ['create', 'Spende anlegen', FilePlus2, '/spenden/anlegen'],
    [
        'open',
        'Offene Zuwendungsbestätigungen',
        FileClock,
        '/spenden/offene-bestaetigungen',
    ],
] as const;
type SignatureMethod = 'digital' | 'profile' | 'drawn' | 'print';
const issueDialogOpen = ref(false);
const selectedDonation = ref<Donation | null>(null);
const issueForm = useForm<{
    signature_method: SignatureMethod;
    signature_data: string | null;
}>({ signature_method: 'digital', signature_data: null });
function issue(donation: Donation) {
    selectedDonation.value = donation;
    issueForm.reset();
    issueForm.signature_method = props.configuration.digital_delivery_allowed
        ? 'digital'
        : 'print';
    issueForm.clearErrors();
    issueDialogOpen.value = true;
}
function submitIssue() {
    if (!selectedDonation.value) return;
    issueForm.post(`/spenden/${selectedDonation.value.id}/ausstellen`, {
        preserveScroll: true,
        onSuccess: () => {
            issueDialogOpen.value = false;
            selectedDonation.value = null;
            issueForm.reset();
        },
    });
}
const sendForm = useForm({});
const revokeDialogOpen = ref(false);
const selectedCertificate = ref<Certificate | null>(null);
const revokeForm = useForm({ reason: '', originals_recovered: false });
function startRevoke(certificate: Certificate) {
    selectedCertificate.value = certificate;
    revokeForm.reset();
    revokeForm.clearErrors();
    revokeDialogOpen.value = true;
}
function submitRevoke() {
    if (!selectedCertificate.value) return;
    revokeForm.post(
        `/spenden/bestaetigungen/${selectedCertificate.value.id}/widerrufen`,
        {
            preserveScroll: true,
            onSuccess: () => {
                revokeDialogOpen.value = false;
                selectedCertificate.value = null;
                revokeForm.reset();
            },
        },
    );
}
const issueError = computed(() => {
    const errors = issueForm.errors as Record<string, string>;

    return (
        errors.signature_method || errors.signature_data || errors.certificate
    );
});
const actionError = computed(
    () =>
        issueError.value ||
        (sendForm.errors as Record<string, string>).email ||
        revokeForm.errors.reason ||
        revokeForm.errors.originals_recovered,
);
function send(certificate: Certificate) {
    sendForm.post(`/spenden/bestaetigungen/${certificate.id}/versenden`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Spenden" />

    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Spenden</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Spenden fortlaufend erfassen und Zuwendungsbestätigungen
                    ausstellen.
                </p>
            </div>
            <a
                v-if="$page.props.can.manageConfiguration"
                href="/konfiguration/spenden"
                class="inline-flex h-9 items-center gap-2 rounded-md border bg-background px-3 text-sm font-medium shadow-xs hover:bg-accent"
                ><Settings2 class="size-4" />Spenden konfigurieren</a
            >
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">Spenden gesamt</p>
                <p class="mt-1 text-2xl font-semibold">{{ summary.count }}</p>
                <p class="text-sm text-muted-foreground">
                    {{ formatMoney(summary.amount_cents) }}
                </p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">
                    Offene Bestätigungen
                </p>
                <p class="mt-1 text-2xl font-semibold">
                    {{ summary.open_count }}
                </p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">Ausgestellt</p>
                <p class="mt-1 text-2xl font-semibold">
                    {{ summary.issued_count }}
                </p>
                <p
                    v-if="summary.revoked_count"
                    class="text-sm text-muted-foreground"
                >
                    davon {{ summary.revoked_count }} widerrufen
                </p>
            </div>
        </div>

        <StatusAlert
            v-if="!configuration.ready"
            type="warning"
            title="Vor der ersten Ausstellung fehlen Stammdaten"
        >
            {{ configuration.errors.join(' ') }}
        </StatusAlert>
        <StatusAlert
            v-if="
                configuration.ready && !configuration.digital_delivery_allowed
            "
            type="info"
            title="Bestätigungen werden nur zum Drucken erstellt"
        >
            Das maschinelle Verfahren wurde dem Finanzamt nicht als angezeigt
            bestätigt. Neue Belege erhalten deshalb ein freies Feld für die
            eigenhändige Unterschrift und können nicht per E-Mail versendet
            werden.
        </StatusAlert>
        <StatusAlert
            v-if="actionError"
            type="error"
            title="Aktion fehlgeschlagen"
        >
            {{ actionError }}
        </StatusAlert>

        <nav
            aria-label="Spendenbereiche"
            class="flex flex-wrap gap-2 border-b pb-4"
        >
            <Link
                v-for="tab in tabs"
                :key="tab[0]"
                :href="tab[3]"
                :aria-current="activeTab === tab[0] ? 'page' : undefined"
                class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:bg-muted"
                :class="
                    activeTab === tab[0]
                        ? 'bg-muted text-foreground'
                        : 'text-muted-foreground'
                "
            >
                <component :is="tab[2]" class="size-4" />{{ tab[1] }}
                <Badge v-if="tab[0] === 'open'" variant="secondary">{{
                    summary.open_count
                }}</Badge>
            </Link>
        </nav>

        <DonationLedger
            v-if="activeTab === 'ledger'"
            :donations="donations"
            :configuration="configuration"
            :issuing="issueForm.processing"
            :sending="sendForm.processing"
            @issue="issue"
            @send="send"
            @revoke="startRevoke"
        />
        <DonationCreateForm
            v-else-if="activeTab === 'create'"
            :purposes="purposes"
            :configuration="configuration"
        />
        <OpenCertificates
            v-else
            :donations="openDonations"
            :configuration="configuration"
            :issuing="issueForm.processing"
            @issue="issue"
        />
        <Dialog v-model:open="issueDialogOpen">
            <DialogContent class="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{{
                        configuration.digital_delivery_allowed
                            ? 'Zuwendungsbestätigung unterzeichnen'
                            : 'Zuwendungsbestätigung zum Drucken erstellen'
                    }}</DialogTitle>
                    <DialogDescription>
                        <template v-if="configuration.digital_delivery_allowed"
                            >{{ $address('Wähle', 'Wählen Sie') }} die
                            Unterschriftsart für</template
                        >
                        <template v-else
                            >Erstelle eine Druckversion mit freiem
                            Unterschriftsfeld für</template
                        >
                        <strong>{{ selectedDonation?.donor_name }}</strong
                        >. Die Bestätigung wird danach unveränderlich
                        ausgestellt.
                    </DialogDescription>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="submitIssue">
                    <fieldset class="space-y-2">
                        <legend
                            v-if="configuration.digital_delivery_allowed"
                            class="text-sm font-medium"
                        >
                            Unterschriftsart
                        </legend>

                        <template v-if="configuration.digital_delivery_allowed">
                            <label
                                class="flex cursor-pointer gap-3 rounded-lg border p-3 has-checked:border-primary has-checked:bg-muted/50"
                            >
                                <input
                                    v-model="issueForm.signature_method"
                                    type="radio"
                                    value="digital"
                                    class="mt-1"
                                />
                                <span>
                                    <span class="block text-sm font-medium"
                                        >Digitale Signatur</span
                                    >
                                    <span
                                        class="block text-xs text-muted-foreground"
                                        >Digitale Freigabe mit prüfbarem
                                        Signaturcode wie bisher.</span
                                    >
                                </span>
                            </label>
                        </template>
                        <StatusAlert
                            v-else
                            type="warning"
                            title="Eigenhändige Unterschrift erforderlich"
                        >
                            Der Beleg wird ausschließlich zum Ausdrucken
                            erstellt. Fordere die verantwortliche Person auf,
                            das ausgedruckte Dokument eigenhändig zu
                            unterschreiben. Ein E-Mail-Versand ist gesperrt.
                        </StatusAlert>

                        <label
                            v-if="configuration.digital_delivery_allowed"
                            class="flex gap-3 rounded-lg border p-3 has-checked:border-primary has-checked:bg-muted/50"
                            :class="
                                props.hasProfileSignature
                                    ? 'cursor-pointer'
                                    : 'cursor-not-allowed opacity-60'
                            "
                        >
                            <input
                                v-model="issueForm.signature_method"
                                type="radio"
                                value="profile"
                                class="mt-1"
                                :disabled="!props.hasProfileSignature"
                            />
                            <span>
                                <span class="block text-sm font-medium"
                                    >Unterschrift aus dem Profil</span
                                >
                                <span
                                    class="block text-xs text-muted-foreground"
                                >
                                    <template v-if="props.hasProfileSignature">
                                        Die hinterlegte Unterschriftsgrafik in
                                        das Dokument einsetzen.
                                    </template>
                                    <template v-else>
                                        Noch keine Unterschrift hinterlegt.
                                        <a
                                            href="/settings/profile"
                                            class="font-medium text-primary hover:underline"
                                            >Jetzt im Profil hochladen</a
                                        >.
                                    </template>
                                </span>
                            </span>
                        </label>

                        <label
                            v-if="configuration.digital_delivery_allowed"
                            class="flex cursor-pointer gap-3 rounded-lg border p-3 has-checked:border-primary has-checked:bg-muted/50"
                        >
                            <input
                                v-model="issueForm.signature_method"
                                type="radio"
                                value="drawn"
                                class="mt-1"
                            />
                            <span>
                                <span class="block text-sm font-medium"
                                    >Mit Maus oder Touch unterschreiben</span
                                >
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >Unterschrift direkt für dieses Dokument
                                    zeichnen.</span
                                >
                            </span>
                        </label>
                    </fieldset>

                    <SignaturePad
                        v-if="issueForm.signature_method === 'drawn'"
                        v-model="issueForm.signature_data"
                    />

                    <InputError :message="issueError" />

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="issueDialogOpen = false"
                        >
                            Abbrechen
                        </Button>
                        <Button
                            type="submit"
                            :disabled="
                                issueForm.processing ||
                                (issueForm.signature_method === 'drawn' &&
                                    !issueForm.signature_data)
                            "
                        >
                            <Spinner v-if="issueForm.processing" />
                            {{
                                issueForm.signature_method === 'print'
                                    ? 'Druckversion verbindlich erstellen'
                                    : 'Verbindlich unterzeichnen & ausstellen'
                            }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="revokeDialogOpen">
            <DialogContent class="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Zuwendungsbestätigung widerrufen</DialogTitle>
                    <DialogDescription>
                        Der Widerruf wird dauerhaft protokolliert. Künftige
                        PDF-Abrufe erhalten ein deutliches Wasserzeichen
                        „WIDERRUFEN“.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="submitRevoke">
                    <StatusAlert
                        type="warning"
                        title="Originale zuerst zurückfordern"
                    >
                        Fordere vor dem Widerruf alle ausgegebenen Originale und
                        Kopien von der spendenden Person zurück und informiere
                        sie, dass die Bestätigung nicht mehr steuerlich
                        verwendet werden darf.
                    </StatusAlert>
                    <div class="space-y-2">
                        <Label for="revocation-reason"
                            >Grund des Widerrufs *</Label
                        >
                        <Textarea
                            id="revocation-reason"
                            v-model="revokeForm.reason"
                            rows="4"
                            maxlength="1000"
                            required
                        />
                        <InputError :message="revokeForm.errors.reason" />
                    </div>
                    <label class="flex items-start gap-3 rounded-lg border p-4">
                        <input
                            v-model="revokeForm.originals_recovered"
                            type="checkbox"
                            class="mt-1 size-4 rounded border-input"
                        />
                        <span class="text-sm"
                            >Ich bestätige, dass alle ausgegebenen Originale und
                            Kopien zurückgefordert wurden.</span
                        >
                    </label>
                    <InputError
                        :message="revokeForm.errors.originals_recovered"
                    />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="revokeDialogOpen = false"
                            >Abbrechen</Button
                        >
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="
                                revokeForm.processing ||
                                !revokeForm.reason.trim() ||
                                !revokeForm.originals_recovered
                            "
                        >
                            <Spinner v-if="revokeForm.processing" /><Ban
                                v-else
                                class="size-4"
                            />Unwiderruflich widerrufen
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
