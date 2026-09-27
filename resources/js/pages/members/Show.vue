<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Check,
    Download,
    FileText,
    Printer,
    Pencil,
    Save,
    Upload,
    X,
} from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import MemberHistoryPanel from '@/components/members/MemberHistory.vue';
import ContributionAccount from '@/components/members/ContributionAccount.vue';
import MemberFieldControl from '@/components/members/MemberFieldControl.vue';
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
import { Input } from '@/components/ui/input';

import { Spinner } from '@/components/ui/spinner';
import { address } from '@/lib/formOfAddress';
import { memberTimestamp, memberValue } from '@/lib/memberFormatting';
import { onBeforeHistoryNavigation } from '@/lib/navigationGuard';
import { index, show, update } from '@/routes/members';
import { store as storeDocument } from '@/routes/members/documents';
import type {
    MemberDetail,
    MemberDocument,
    MemberHistory,
    MemberMandate,
    MemberSection,
    MemberValue,
} from '@/types/members';

const props = defineProps<{
    member: MemberDetail;
    sections: MemberSection[];
    documents: MemberDocument[];
    mandates: MemberMandate[];
    history: MemberHistory;
    canEdit: boolean;
    returnTo: string;
    configurationVersion: number;
    contributionAccount: {
        balance_cents: number;
        transactions: Array<{
            id: number;
            kind: string;
            amount_cents: number;
            booking_date: string;
            description: string;
            reference: string | null;
            actor_name: string;
        }>;
    };
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Mitglieder', href: index() },
            { title: 'Mitgliedsansicht' },
        ],
    },
});
const editing = ref(false);
const fields = computed(() =>
    props.sections.flatMap((section) => section.fields),
);
const fullName = computed(() =>
    [props.member.first_name, props.member.middle_name, props.member.last_name]
        .filter(Boolean)
        .join(' '),
);
function values(): Record<string, MemberValue> {
    return Object.fromEntries([
        ...fields.value
            .filter((field) => !field.readOnly)
            .map((field) => [field.key, props.member[field.key] ?? null]),
        ['lock_version', props.member.lock_version],
    ]);
}
const form = useForm(values());
const discardOpen = ref(false);
const transportError = ref('');
let pendingLeave: (() => void) | undefined;
let allowNavigation = false;
let removeGuard: (() => void) | undefined;
let removeHistoryGuard: (() => void) | undefined;
let removeNavigationListener: (() => void) | undefined;
let lastUrl = '';
let lastState: unknown;
type DocumentKind = 'application' | 'sepa';
const documentKinds: Array<{ kind: DocumentKind; label: string }> = [
    { kind: 'application', label: 'Mitgliedsantrag' },
];
const documentUploads = {
    application: useForm<{ document: File | null }>({ document: null }),
    sepa: useForm<{ document: File | null }>({ document: null }),
};
const dirty = computed(
    () =>
        editing.value &&
        (form.isDirty ||
            documentUploads.application.isDirty ||
            documentUploads.sepa.isDirty),
);
const documentProcessing = computed(
    () =>
        documentUploads.application.processing ||
        documentUploads.sepa.processing,
);
const revokesMandate = computed(
    () =>
        editing.value &&
        props.member.payment_method === 'SEPA-Lastschrift' &&
        form.payment_method !== 'SEPA-Lastschrift',
);

function requestLeave(action: () => void) {
    if (form.processing || documentProcessing.value) return;
    if (dirty.value) {
        pendingLeave = action;
        discardOpen.value = true;
    } else action();
}
function discard() {
    discardOpen.value = false;
    form.reset();
    documentUploads.application.reset();
    documentUploads.sepa.reset();
    editing.value = false;
    pendingLeave?.();
    pendingLeave = undefined;
}
function close() {
    requestLeave(() => router.get(props.returnTo));
}
async function edit() {
    editing.value = true;
    await nextTick();
    document
        .getElementById('member-first_name')
        ?.focus({ preventScroll: true });
}
function loadCurrent() {
    requestLeave(() =>
        router.get(
            show.url(props.member.member_number),
            { return_to: props.returnTo },
            { replace: true, preserveScroll: true },
        ),
    );
}
function save(closeAfter = false) {
    if (form.processing) return;
    transportError.value = '';
    allowNavigation = true;
    form.transform((data) => ({
        ...data,
        return_to: props.returnTo,
        close: closeAfter,
        configuration_version: props.configurationVersion,
    })).patch(update.url(props.member.member_number), {
        preserveScroll: !closeAfter,
        onSuccess: () => {
            editing.value = false;
            form.defaults(values());
            form.reset();
        },
        onError: async () => {
            await nextTick();
            const invalid = document.querySelector<HTMLElement>(
                '[aria-invalid="true"]',
            );
            if (invalid) invalid.focus();
            else document.getElementById('member-form-error')?.focus();
        },
        onHttpException: (response) => {
            transportError.value =
                response.status === 419
                    ? address(
                          'Deine Sitzung ist abgelaufen. Bitte sichere deine Eingaben und melde dich erneut an.',
                          'Ihre Sitzung ist abgelaufen. Bitte sichern Sie Ihre Eingaben und melden Sie sich erneut an.',
                      )
                    : response.status === 403
                      ? address(
                            'Du hast keine Berechtigung, dieses Mitglied zu bearbeiten.',
                            'Sie haben keine Berechtigung, dieses Mitglied zu bearbeiten.',
                        )
                      : address(
                            'Das Speichern konnte nicht bestätigt werden. Deine Eingaben bleiben erhalten. Bitte versuche es erneut.',
                            'Das Speichern konnte nicht bestätigt werden. Ihre Eingaben bleiben erhalten. Bitte versuchen Sie es erneut.',
                        );
            return false;
        },
        onNetworkError: () => {
            transportError.value = address(
                'Keine Verbindung zum Server. Deine Eingaben bleiben erhalten. Bitte prüfe die Verbindung und versuche es erneut.',
                'Keine Verbindung zum Server. Ihre Eingaben bleiben erhalten. Bitte prüfen Sie die Verbindung und versuchen Sie es erneut.',
            );
            return false;
        },
        onFinish: () => {
            allowNavigation = false;
        },
    });
}

function beforeUnload(event: BeforeUnloadEvent) {
    if (dirty.value || form.processing || documentProcessing.value) {
        event.preventDefault();
        event.returnValue = '';
    }
}
function beforePopState(event: PopStateEvent) {
    if (!dirty.value && !form.processing) return;
    if (
        !form.processing &&
        window.confirm(
            'Ungespeicherte Änderungen verwerfen und diese Ansicht verlassen?',
        )
    )
        return;
    // Inertia cannot cancel a popstate visit. Keep this form and restore its URL.
    event.stopImmediatePropagation();
    window.history.pushState(lastState, '', lastUrl);
}
onMounted(() => {
    lastUrl = window.location.href;
    lastState = window.history.state;
    window.addEventListener('beforeunload', beforeUnload);
    removeHistoryGuard = onBeforeHistoryNavigation(beforePopState);
    removeNavigationListener = router.on('navigate', () => {
        lastUrl = window.location.href;
        lastState = window.history.state;
    });
    removeGuard = router.on('before', (event) => {
        if (
            allowNavigation &&
            ['patch', 'post'].includes(event.detail.visit.method)
        )
            return;
        if (form.processing) return false;
        if (!dirty.value) return;
        const visit = event.detail.visit;
        requestLeave(() => router.visit(visit.url, visit));
        return false;
    });
});
onBeforeUnmount(() => {
    removeGuard?.();
    removeHistoryGuard?.();
    removeNavigationListener?.();
    window.removeEventListener('beforeunload', beforeUnload);
});
function documentFor(kind: DocumentKind) {
    return props.documents.find((document) => document.kind === kind);
}
function dateOnly(value: string | null) {
    return value
        ? value.slice(0, 10).split('-').reverse().join('.')
        : 'Nicht hinterlegt';
}
function chooseDocument(kind: DocumentKind, event: Event) {
    documentUploads[kind].document =
        (event.target as HTMLInputElement).files?.[0] ?? null;
}
function uploadDocument(kind: DocumentKind) {
    const upload = documentUploads[kind];
    if (!upload.document || upload.processing) return;
    allowNavigation = true;
    upload.post(
        storeDocument.url({ member: props.member.member_number, kind }),
        {
            preserveScroll: true,
            onSuccess: () => upload.reset(),
            onFinish: () => {
                allowNavigation = false;
            },
        },
    );
}
const adult = computed(() => {
    if (!props.member.birth_date) return 'Nicht bestimmbar';
    const birthday = new Date(`${props.member.birth_date}T00:00:00`);
    birthday.setFullYear(birthday.getFullYear() + 18);
    return birthday <= new Date() ? 'Ja' : 'Nein';
});
</script>

<template>
    <Head :title="fullName" />
    <form
        class="mx-auto flex w-full max-w-[1200px] flex-col gap-6 p-4 sm:p-6"
        novalidate
        data-test="member-detail"
        @submit.prevent="save()"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <Button
                type="button"
                variant="ghost"
                class="-ml-3 text-muted-foreground"
                :disabled="form.processing"
                @click="close"
                ><ArrowLeft class="size-4" />Zur Mitgliederliste</Button
            >
            <span
                v-if="dirty"
                role="status"
                class="text-xs font-medium text-amber-700 dark:text-amber-300"
                >Ungespeicherte Änderungen</span
            >
            <span
                v-else-if="form.recentlySuccessful"
                role="status"
                class="flex items-center gap-1 text-xs text-emerald-700 dark:text-emerald-300"
                ><Check class="size-3.5" />Gespeichert</span
            >
            <Button type="button" variant="outline" size="sm" as-child
                ><a
                    :href="`/mitglieder/${member.member_number}/karteiblatt`"
                    target="_blank"
                    rel="noopener noreferrer"
                    title="Karteiblatt mit dem gespeicherten Datenstand öffnen"
                    data-test="print-member-card"
                    ><Printer class="size-4" />Karteiblatt / Ausdruck</a
                ></Button
            >
        </div>

        <header
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex min-w-0 items-center gap-4">
                <span
                    class="flex size-14 shrink-0 items-center justify-center rounded-2xl border bg-muted text-lg font-semibold"
                    aria-hidden="true"
                    >{{
                        member.first_name.charAt(0) + member.last_name.charAt(0)
                    }}</span
                >
                <div class="min-w-0">
                    <p
                        class="mb-1 text-xs font-medium tracking-wider text-muted-foreground uppercase"
                    >
                        {{ `Mitglied Nr. ${member.member_number}` }}
                    </p>
                    <h1
                        class="text-2xl font-semibold tracking-tight break-words"
                    >
                        {{ fullName }}
                    </h1>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <Badge variant="secondary">{{
                            member.membership_type
                        }}</Badge
                        ><Badge v-if="member.is_honorary" variant="outline"
                            >Ehrenmitglied</Badge
                        ><Badge v-if="member.left_at" variant="outline"
                            >Ausgetreten</Badge
                        >
                    </div>
                </div>
            </div>
            <Button
                v-if="canEdit && !editing"
                type="button"
                data-test="edit-member"
                @click="edit"
                ><Pencil class="size-4" />Bearbeiten</Button
            >
            <span v-if="!canEdit" class="text-sm text-muted-foreground"
                >Lesezugriff</span
            >
        </header>

        <StatusAlert
            v-if="
                form.errors.lock_version || form.errors.form || transportError
            "
            id="member-form-error"
            type="error"
            title="Änderungen nicht gespeichert"
            tabindex="-1"
        >
            <p>
                {{
                    form.errors.lock_version ||
                    form.errors.form ||
                    transportError
                }}
            </p>
            <Button
                v-if="form.errors.lock_version || form.errors.form"
                type="button"
                variant="outline"
                class="mt-3"
                @click="loadCurrent"
                >Aktuellen Stand laden</Button
            >
        </StatusAlert>
        <StatusAlert
            v-if="revokesMandate"
            type="warning"
            title="SEPA-Mandat wird widerrufen"
        >
            Beim Speichern wird das aktive Mandat widerrufen und aus den
            aktuellen Bankdaten entfernt. Das PDF bleibt in der Mandatshistorie
            der Verwaltung erhalten.
        </StatusAlert>

        <div
            class="grid min-w-0 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_340px]"
        >
            <div class="min-w-0 space-y-5">
                <section
                    v-for="(section, sectionIndex) in sections"
                    :key="section.title"
                    class="rounded-xl border bg-card"
                    :aria-labelledby="`section-${sectionIndex}`"
                >
                    <div class="border-b px-5 py-4">
                        <h2
                            :id="`section-${sectionIndex}`"
                            class="text-sm font-semibold"
                        >
                            {{ section.title }}
                        </h2>
                    </div>
                    <div class="grid gap-x-6 gap-y-5 p-5 md:grid-cols-2">
                        <div
                            v-for="field in section.fields"
                            :key="field.key"
                            class="min-w-0 space-y-2"
                        >
                            <Label
                                v-if="editing && !field.readOnly"
                                :for="`member-${field.key}`"
                                class="text-xs text-muted-foreground"
                                >{{ field.label
                                }}<span
                                    v-if="field.required"
                                    aria-hidden="true"
                                >
                                    *</span
                                ></Label
                            >
                            <template v-if="editing && !field.readOnly">
                                <MemberFieldControl
                                    :field="field"
                                    :value="form[field.key]"
                                    :values="form.data()"
                                    :disabled="form.processing"
                                    :error="form.errors[field.key]"
                                    @change="form[field.key] = $event"
                                />
                                <InputError
                                    :id="`error-${field.key}`"
                                    :message="form.errors[field.key]"
                                />
                            </template>
                            <dl v-else>
                                <dt class="text-xs text-muted-foreground">
                                    {{ field.label }}
                                </dt>
                                <dd
                                    class="mt-2 text-sm break-words"
                                    :class="{
                                        'text-muted-foreground':
                                            member[field.key] === null ||
                                            member[field.key] === '',
                                    }"
                                    :data-field="field.key"
                                >
                                    {{ memberValue(member[field.key], field) }}
                                </dd>
                            </dl>
                        </div>
                        <dl
                            v-if="
                                section.key === 'personal' &&
                                section.fields.some(
                                    (field) => field.key === 'birth_date',
                                )
                            "
                        >
                            <dt class="text-xs text-muted-foreground">
                                Volljährig
                            </dt>
                            <dd class="mt-2 text-sm">{{ adult }}</dd>
                        </dl>
                    </div>
                </section>

                <section
                    class="rounded-xl border bg-card"
                    aria-labelledby="documents-title"
                >
                    <h2
                        id="documents-title"
                        class="border-b px-5 py-4 text-sm font-semibold"
                    >
                        Dokumente
                    </h2>
                    <div class="divide-y px-5">
                        <div
                            v-for="document in documentKinds"
                            :key="document.kind"
                            class="flex flex-wrap items-center justify-between gap-3 py-4"
                        >
                            <div class="flex items-center gap-3">
                                <FileText
                                    class="size-5 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <div>
                                    <p class="text-sm font-medium">
                                        {{ document.label }}
                                    </p>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {{
                                            documentFor(document.kind)
                                                ? documentFor(document.kind)
                                                      ?.submitted_online
                                                    ? 'Online eingereicht'
                                                    : 'Hinterlegt'
                                                : 'Kein Dokument hinterlegt'
                                        }}
                                    </p>
                                </div>
                            </div>
                            <Button
                                v-if="documentFor(document.kind)"
                                as-child
                                variant="outline"
                                size="sm"
                                ><a
                                    :href="documentFor(document.kind)?.url"
                                    target="_blank"
                                    rel="noopener"
                                    ><Download class="size-4" />PDF
                                    herunterladen</a
                                ></Button
                            >
                            <div
                                v-if="editing"
                                class="basis-full space-y-2 rounded-lg border bg-muted/30 p-3"
                            >
                                <Label
                                    :for="`document-${document.kind}`"
                                    class="text-xs"
                                    >{{
                                        documentFor(document.kind)
                                            ? `${document.label} ersetzen`
                                            : `${document.label} hochladen`
                                    }}</Label
                                >
                                <div class="flex flex-col gap-2 sm:flex-row">
                                    <Input
                                        :id="`document-${document.kind}`"
                                        type="file"
                                        accept="application/pdf,.pdf"
                                        :disabled="
                                            documentUploads[document.kind]
                                                .processing
                                        "
                                        :aria-invalid="
                                            !!documentUploads[document.kind]
                                                .errors.document
                                        "
                                        :aria-describedby="`error-document-${document.kind}`"
                                        @change="
                                            chooseDocument(
                                                document.kind,
                                                $event,
                                            )
                                        "
                                    />
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        :disabled="
                                            !documentUploads[document.kind]
                                                .document ||
                                            documentUploads[document.kind]
                                                .processing
                                        "
                                        @click="uploadDocument(document.kind)"
                                        ><Spinner
                                            v-if="
                                                documentUploads[document.kind]
                                                    .processing
                                            "
                                        /><Upload v-else class="size-4" />{{
                                            documentFor(document.kind)
                                                ? 'Ersetzen'
                                                : 'Hochladen'
                                        }}</Button
                                    >
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    PDF, maximal 10 MB
                                </p>
                                <InputError
                                    :id="`error-document-${document.kind}`"
                                    :message="
                                        documentUploads[document.kind].errors
                                            .document
                                    "
                                />
                            </div>
                        </div>
                    </div>
                </section>
                <section
                    class="rounded-xl border bg-card"
                    aria-labelledby="mandates-title"
                >
                    <div class="border-b px-5 py-4">
                        <h2 id="mandates-title" class="text-sm font-semibold">
                            SEPA-Mandatshistorie
                        </h2>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Aktuelle und widerrufene Mandate bleiben vollständig
                            nachvollziehbar.
                        </p>
                    </div>
                    <div v-if="mandates.length" class="divide-y px-5">
                        <div
                            v-for="mandate in mandates"
                            :key="mandate.id"
                            class="flex flex-wrap items-start justify-between gap-4 py-4"
                        >
                            <div class="min-w-0 space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-sm font-medium">
                                        {{
                                            mandate.mandate_reference ||
                                            `Mandat #${mandate.id}`
                                        }}
                                    </p>
                                    <Badge
                                        :variant="
                                            mandate.active
                                                ? 'default'
                                                : 'secondary'
                                        "
                                    >
                                        {{
                                            mandate.active
                                                ? 'Aktiv'
                                                : 'Widerrufen / archiviert'
                                        }}
                                    </Badge>
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    Unterzeichnet:
                                    {{ dateOnly(mandate.mandate_signed_at) }} ·
                                    {{
                                        mandate.submitted_online
                                            ? 'Online eingereicht'
                                            : 'Manuell hinterlegt'
                                    }}
                                </p>
                                <p
                                    v-if="mandate.revoked_at"
                                    class="text-xs text-muted-foreground"
                                >
                                    Widerrufen:
                                    {{ memberTimestamp(mandate.revoked_at) }}
                                    <template v-if="mandate.revocation_reason">
                                        · {{ mandate.revocation_reason }}
                                    </template>
                                </p>
                            </div>
                            <Button as-child variant="outline" size="sm">
                                <a
                                    :href="mandate.url"
                                    target="_blank"
                                    rel="noopener"
                                    ><Download class="size-4" />PDF
                                    herunterladen</a
                                >
                            </Button>
                        </div>
                    </div>
                    <p v-else class="px-5 py-4 text-sm text-muted-foreground">
                        Noch kein SEPA-Mandat hinterlegt.
                    </p>
                    <div
                        v-if="editing"
                        class="m-5 space-y-2 rounded-lg border bg-muted/30 p-3"
                    >
                        <Label for="document-sepa" class="text-xs">
                            Neues SEPA-Mandat zur Historie hinzufügen
                        </Label>
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <Input
                                id="document-sepa"
                                type="file"
                                accept="application/pdf,.pdf"
                                :disabled="documentUploads.sepa.processing"
                                :aria-invalid="
                                    !!documentUploads.sepa.errors.document
                                "
                                aria-describedby="error-document-sepa"
                                @change="chooseDocument('sepa', $event)"
                            />
                            <Button
                                type="button"
                                variant="secondary"
                                :disabled="
                                    !documentUploads.sepa.document ||
                                    documentUploads.sepa.processing
                                "
                                @click="uploadDocument('sepa')"
                            >
                                <Spinner
                                    v-if="documentUploads.sepa.processing"
                                />
                                <Upload v-else class="size-4" />Hochladen
                            </Button>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            PDF, maximal 10 MB. Ein bisher aktives Dokument wird
                            archiviert.
                        </p>
                        <InputError
                            id="error-document-sepa"
                            :message="documentUploads.sepa.errors.document"
                        />
                    </div>
                </section>
                <p class="text-xs leading-relaxed text-muted-foreground">
                    {{
                        `Angelegt am ${memberTimestamp(member.created_at)} · Zuletzt geändert am ${memberTimestamp(member.updated_at)} · Version ${member.lock_version}`
                    }}
                </p>
            </div>
            <div class="min-w-0 space-y-6">
                <ContributionAccount :account="contributionAccount" />
                <MemberHistoryPanel
                    :history="history"
                    :sections="sections"
                    :disabled="editing || form.processing"
                />
            </div>
        </div>

        <footer
            class="sticky bottom-0 z-20 -mx-4 -mb-4 flex flex-wrap items-center justify-end gap-2 border-t bg-background/95 px-4 py-3 backdrop-blur sm:-mx-6 sm:-mb-6 sm:px-6"
        >
            <span v-if="editing" class="mr-auto text-xs text-muted-foreground"
                >* Pflichtfelder</span
            >
            <Button
                type="button"
                variant="outline"
                data-test="close-member"
                :disabled="form.processing"
                @click="close"
                ><X class="size-4" />Schließen</Button
            >
            <template v-if="editing">
                <Button
                    type="submit"
                    variant="secondary"
                    data-test="save-member"
                    :disabled="form.processing"
                    ><Spinner v-if="form.processing" /><Save
                        v-else
                        class="size-4"
                    />Speichern</Button
                >
                <Button
                    type="button"
                    data-test="save-close-member"
                    :disabled="form.processing"
                    @click="save(true)"
                    >Speichern und Schließen</Button
                >
            </template>
        </footer>
    </form>

    <Dialog v-model:open="discardOpen">
        <DialogContent>
            <DialogHeader
                ><DialogTitle>Änderungen verwerfen?</DialogTitle
                ><DialogDescription>{{
                    $address(
                        'Du hast ungespeicherte Änderungen. Beim Verlassen gehen diese Eingaben verloren.',
                        'Sie haben ungespeicherte Änderungen. Beim Verlassen gehen diese Eingaben verloren.',
                    )
                }}</DialogDescription></DialogHeader
            >
            <DialogFooter
                ><Button
                    type="button"
                    variant="outline"
                    @click="discardOpen = false"
                    >Weiter bearbeiten</Button
                ><Button
                    type="button"
                    variant="destructive"
                    data-test="discard-member-changes"
                    @click="discard"
                    >Änderungen verwerfen</Button
                ></DialogFooter
            >
        </DialogContent>
    </Dialog>
</template>
