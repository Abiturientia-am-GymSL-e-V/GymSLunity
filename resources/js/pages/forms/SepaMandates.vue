<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { List, Plus } from '@lucide/vue';
import { ref } from 'vue';
import CountryInput from '@/components/CountryInput.vue';
import MandateBook from '@/components/forms/MandateBook.vue';
import IbanInput from '@/components/IbanInput.vue';
import InputError from '@/components/InputError.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type {
    FinanceMandate,
    FinanceMandateFilters,
    FinanceMandatePage,
} from '@/types/mandates';

const props = defineProps<{
    activeTab: 'overview' | 'create';
    mandates: FinanceMandatePage;
    search: string;
    filters: FinanceMandateFilters;
    creationKey: string;
    today: string;
    defaultCountry: string;
    clubReady: boolean;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Formulare', href: '/formulare' },
            { title: 'SEPA-FinanceMandate' },
        ],
    },
});
const createForm = useForm({
    creation_key: props.creationKey,
    debtor_name: '',
    debtor_street: '',
    debtor_postal_code: '',
    debtor_city: '',
    debtor_country: props.defaultCountry || 'DE',
    debtor_email: '',
    iban: '',
    mandate_type: 'recurring' as 'recurring' | 'one_off',
});
const selected = ref<FinanceMandate | null>(null);
const dialog = ref<'send' | 'sign' | 'revoke' | null>(null);
const sendForm = useForm({ recipient: '' });
const signForm = useForm({ signed_by_name: '', signed_at: props.today });
const revokeForm = useForm({ reason: '' });
function openSend(mandate: FinanceMandate) {
    selected.value = mandate;
    sendForm.reset();
    sendForm.clearErrors();
    sendForm.recipient = mandate.debtor_email ?? '';
    dialog.value = 'send';
}
function openSign(mandate: FinanceMandate) {
    selected.value = mandate;
    signForm.reset();
    signForm.clearErrors();
    signForm.signed_by_name = mandate.debtor_name;
    signForm.signed_at = props.today;
    dialog.value = 'sign';
}
function openRevoke(mandate: FinanceMandate) {
    selected.value = mandate;
    revokeForm.reset();
    revokeForm.clearErrors();
    dialog.value = 'revoke';
}
function send() {
    if (!selected.value) return;
    sendForm.post(`/formulare/sepa-mandate/${selected.value.id}/versenden`, {
        preserveScroll: true,
        onSuccess: () => (dialog.value = null),
    });
}
function markSigned() {
    if (!selected.value) return;
    signForm.post(
        `/formulare/sepa-mandate/${selected.value.id}/unterschrieben`,
        { preserveScroll: true, onSuccess: () => (dialog.value = null) },
    );
}
function revoke() {
    if (!selected.value) return;
    revokeForm.post(`/formulare/sepa-mandate/${selected.value.id}/widerrufen`, {
        preserveScroll: true,
        onSuccess: () => (dialog.value = null),
    });
}
</script>

<template>
    <Head title="SEPA-Mandate" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">SEPA-Mandate</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Mandate für Rechnungen anlegen, versenden und unterschreiben
                lassen.
            </p>
        </header>
        <nav
            class="flex flex-wrap gap-2 border-b pb-4"
            aria-label="SEPA-Mandate"
        >
            <Link
                href="/formulare/sepa-mandate"
                :aria-current="activeTab === 'overview' ? 'page' : undefined"
                prefetch
                class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:bg-muted"
                :class="
                    activeTab === 'overview'
                        ? 'bg-muted text-foreground'
                        : 'text-muted-foreground'
                "
                ><List class="size-4" />Übersicht ({{ mandates.total }})</Link
            >
            <Link
                href="/formulare/sepa-mandate/anlegen"
                :aria-current="activeTab === 'create' ? 'page' : undefined"
                prefetch
                class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:bg-muted"
                :class="
                    activeTab === 'create'
                        ? 'bg-muted text-foreground'
                        : 'text-muted-foreground'
                "
                ><Plus class="size-4" />Mandat anlegen</Link
            >
        </nav>

        <template v-if="activeTab === 'overview'">
            <MandateBook
                :mandates="mandates"
                :filters="filters"
                @send="openSend"
                @sign="openSign"
                @revoke="openRevoke"
            />
        </template>

        <form
            v-else
            class="space-y-6"
            @submit.prevent="createForm.post('/formulare/sepa-mandate')"
        >
            <StatusAlert
                v-if="!clubReady"
                type="warning"
                title="Vereinsdaten vervollständigen"
                >Für Mandate müssen Vereinsname, Anschrift, Land und
                Gläubiger-ID in der
                <Link href="/konfiguration/verein" class="font-medium underline"
                    >Vereinskonfiguration</Link
                >
                hinterlegt sein.</StatusAlert
            >
            <section class="rounded-xl border bg-card">
                <div class="border-b px-5 py-4">
                    <h2 class="font-semibold">Zahlungspflichtige Person</h2>
                </div>
                <div class="grid gap-5 p-5 sm:grid-cols-2">
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="debtor-name">Name *</Label
                        ><Input
                            id="debtor-name"
                            v-model="createForm.debtor_name"
                            maxlength="255"
                            required
                        /><InputError
                            :message="createForm.errors.debtor_name"
                        />
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="debtor-street"
                            >Straße und Hausnummer *</Label
                        ><Input
                            id="debtor-street"
                            v-model="createForm.debtor_street"
                            maxlength="255"
                            required
                        /><InputError
                            :message="createForm.errors.debtor_street"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="debtor-postal-code">Postleitzahl *</Label
                        ><Input
                            id="debtor-postal-code"
                            v-model="createForm.debtor_postal_code"
                            maxlength="20"
                            required
                        /><InputError
                            :message="createForm.errors.debtor_postal_code"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="debtor-city">Ort *</Label
                        ><Input
                            id="debtor-city"
                            v-model="createForm.debtor_city"
                            maxlength="255"
                            required
                        /><InputError
                            :message="createForm.errors.debtor_city"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="debtor-country">Land *</Label
                        ><CountryInput
                            id="debtor-country"
                            v-model="createForm.debtor_country"
                        /><InputError
                            :message="createForm.errors.debtor_country"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="debtor-email">E-Mail (optional)</Label
                        ><Input
                            id="debtor-email"
                            v-model="createForm.debtor_email"
                            type="email"
                            maxlength="255"
                        /><InputError
                            :message="createForm.errors.debtor_email"
                        />
                    </div>
                </div>
            </section>
            <section class="rounded-xl border bg-card">
                <div class="border-b px-5 py-4">
                    <h2 class="font-semibold">Bankverbindung & Verwendung</h2>
                </div>
                <div class="grid gap-5 p-5 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label for="mandate-iban">IBAN *</Label
                        ><IbanInput
                            id="mandate-iban"
                            v-model="createForm.iban"
                            required
                        /><InputError :message="createForm.errors.iban" />
                    </div>
                    <div class="space-y-2">
                        <Label for="mandate-type">Mandatsart *</Label
                        ><select
                            id="mandate-type"
                            v-model="createForm.mandate_type"
                            class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="recurring">
                                Wiederkehrende Zahlungen
                            </option>
                            <option value="one_off">
                                Einmalige Zahlung
                            </option></select
                        ><InputError
                            :message="createForm.errors.mandate_type"
                        />
                    </div>
                    <StatusAlert
                        class="sm:col-span-2"
                        type="info"
                        title="Zunächst nicht verwendbar"
                        >Das System vergibt automatisch eine eindeutige
                        Referenz. Erst nach digitaler Unterzeichnung oder
                        manueller Bestätigung einer Papierunterschrift kann das
                        Mandat bei einer Rechnung gewählt werden.</StatusAlert
                    >
                </div>
            </section>
            <StatusAlert
                v-if="Object.keys(createForm.errors).length"
                type="error"
                title="Mandat nicht angelegt"
                :messages="Object.values(createForm.errors)"
            />
            <div class="flex justify-end">
                <Button :disabled="createForm.processing || !clubReady"
                    ><Spinner v-if="createForm.processing" /><Plus
                        v-else
                        class="size-4"
                    />Mandat anlegen</Button
                >
            </div>
        </form>
    </div>

    <Dialog :open="dialog === 'send'" @update:open="!$event && (dialog = null)"
        ><DialogContent
            ><DialogHeader
                ><DialogTitle>Mandat per E-Mail senden</DialogTitle
                ><DialogDescription
                    >Die Nachricht enthält die PDF und einen persönlichen Link
                    zur digitalen Unterzeichnung.</DialogDescription
                ></DialogHeader
            >
            <form class="space-y-4" @submit.prevent="send">
                <div class="space-y-2">
                    <Label for="mandate-recipient">Empfängeradresse</Label
                    ><Input
                        id="mandate-recipient"
                        v-model="sendForm.recipient"
                        type="email"
                        required
                    /><InputError :message="sendForm.errors.recipient" />
                </div>
                <DialogFooter
                    ><Button
                        type="button"
                        variant="outline"
                        @click="dialog = null"
                        >Abbrechen</Button
                    ><Button :disabled="sendForm.processing"
                        ><Spinner v-if="sendForm.processing" />Senden</Button
                    ></DialogFooter
                >
            </form></DialogContent
        ></Dialog
    >
    <Dialog :open="dialog === 'sign'" @update:open="!$event && (dialog = null)"
        ><DialogContent
            ><DialogHeader
                ><DialogTitle>Papierunterschrift bestätigen</DialogTitle
                ><DialogDescription
                    >Bestätige nur, wenn das unterschriebene Original vorliegt.
                    Danach ist das Mandat für Lastschriften
                    verwendbar.</DialogDescription
                ></DialogHeader
            >
            <form class="space-y-4" @submit.prevent="markSigned">
                <div class="space-y-2">
                    <Label for="signed-by-name">Unterschrieben von *</Label
                    ><Input
                        id="signed-by-name"
                        v-model="signForm.signed_by_name"
                        required
                    /><InputError :message="signForm.errors.signed_by_name" />
                </div>
                <div class="space-y-2">
                    <Label for="signed-at">Unterschrieben am *</Label
                    ><Input
                        id="signed-at"
                        v-model="signForm.signed_at"
                        type="date"
                        :max="today"
                        required
                    /><InputError :message="signForm.errors.signed_at" />
                </div>
                <DialogFooter
                    ><Button
                        type="button"
                        variant="outline"
                        @click="dialog = null"
                        >Abbrechen</Button
                    ><Button :disabled="signForm.processing"
                        ><Spinner v-if="signForm.processing" />Als
                        unterschrieben markieren</Button
                    ></DialogFooter
                >
            </form></DialogContent
        ></Dialog
    >
    <Dialog
        :open="dialog === 'revoke'"
        @update:open="!$event && (dialog = null)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>SEPA-Mandat widerrufen</DialogTitle>
                <DialogDescription>
                    Das Mandat kann danach weder unterzeichnet noch für neue
                    Rechnungen verwendet werden. Das bisherige PDF bleibt als
                    Nachweis erhalten.
                </DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="revoke">
                <div class="space-y-2">
                    <Label for="revocation-reason">Begründung *</Label>
                    <Textarea
                        id="revocation-reason"
                        v-model="revokeForm.reason"
                        maxlength="1000"
                        required
                    />
                    <InputError :message="revokeForm.errors.reason" />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="dialog = null"
                    >
                        Abbrechen
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="revokeForm.processing"
                    >
                        <Spinner v-if="revokeForm.processing" />
                        Mandat widerrufen
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
