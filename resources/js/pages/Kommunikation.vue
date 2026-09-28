<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Download,
    FileText,
    History,
    Mail,
    Paperclip,
    Send,
    X,
} from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import CommunicationHistory from '@/components/communication/CommunicationHistory.vue';
import RecipientFilter from '@/components/communication/RecipientFilter.vue';
import RecipientPreview from '@/components/communication/RecipientPreview.vue';
import RichTextEditor from '@/components/communication/RichTextEditor.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatNumber } from '@/lib/format';
import type {
    Campaign,
    CommunicationTab,
    Delivery,
    RecipientFilterOptions,
    RecipientFilters,
    RecipientPreviewRow,
    RecipientSummary,
} from '@/types/communication';
import type { MemberField } from '@/types/members';

const props = defineProps<{
    activeTab: CommunicationTab;
    filters: RecipientFilters;
    filterOptions: RecipientFilterOptions;
    customFilters: MemberField[];
    summary: RecipientSummary;
    preview: RecipientPreviewRow[];
    placeholders: { token: string; label: string; group: string }[];
    mailConfiguration: {
        driver: string;
        from_address: string;
        from_name: string;
    };
    defaults: { subject: string; body: string };
    campaigns: Campaign[];
    selectedCampaign: Campaign | null;
    deliveries: Delivery[];
    csrfToken: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Kommunikation', href: '/kommunikation' }],
    },
});

const tabs = [
    ['mail', 'Serien-E-Mails', Mail, '/kommunikation/serienmails'],
    ['letters', 'Serienbriefe', FileText, '/kommunikation/serienbriefe'],
    ['history', 'Verlauf', History, '/kommunikation/verlauf'],
] as const;
const pageCopy: Record<CommunicationTab, { title: string; subtitle: string }> =
    {
        mail: {
            title: 'Serien-E-Mails',
            subtitle:
                'Personalisierte Nachrichten einzeln an gefilterte Mitglieder versenden.',
        },
        letters: {
            title: 'Serienbriefe',
            subtitle:
                'Personalisierte Briefe als Gesamt-PDF oder ZIP mit Einzeldateien erstellen.',
        },
        history: {
            title: 'Kommunikationsverlauf',
            subtitle: 'Versand- und Exportvorgänge nachvollziehen.',
        },
    };
const filterDraft = reactive<RecipientFilters>({
    ...props.filters,
    custom: { ...props.filters.custom },
});
const currentQuery = computed(() => {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(filterDraft)) {
        if (key === 'custom') {
            for (const [customKey, customValue] of Object.entries(
                value as Record<string, string>,
            ))
                if (customValue)
                    params.set(`custom[${customKey}]`, customValue);
        } else if (value) params.set(key, String(value));
    }
    return params.toString();
});
const tabUrl = (path: string) =>
    `${path}${currentQuery.value ? `?${currentQuery.value}` : ''}`;
const activePath = computed(
    () =>
        tabs.find(([key]) => key === props.activeTab)?.[3] ??
        '/kommunikation/serienmails',
);
const applyFilters = () =>
    router.get(activePath.value, filterDraft, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
const resetFilters = () => {
    Object.assign(filterDraft, {
        q: '',
        status: 'active',
        membership: '',
        department_role: '',
        club_role: '',
        gender: '',
        payment_method: '',
        city: '',
        honorary: '',
        email_status: '',
        address_status: '',
        joined_from: '',
        joined_to: '',
        custom: {},
    });
    applyFilters();
};
watch(
    () => props.filters,
    (filters) =>
        Object.assign(filterDraft, filters, { custom: { ...filters.custom } }),
);

type MailForm = {
    subject: string;
    body: string;
    confirmed: boolean;
    attachments: File[];
};
const mailForm = useForm<MailForm>({
    subject: props.defaults.subject,
    body: props.defaults.body,
    confirmed: false,
    attachments: [],
});
const mailRecipientError = computed(
    () => (mailForm.errors as Record<string, string>).recipients,
);
const page = usePage();
const serverErrors = computed(
    () => (page.props.errors ?? {}) as Record<string, string>,
);
const letter = reactive({
    subject: props.defaults.subject,
    body: props.defaults.body,
    format: 'pdf',
    confirmed: false,
});
const mailEditor = ref<InstanceType<typeof RichTextEditor> | null>(null);
const letterEditor = ref<InstanceType<typeof RichTextEditor> | null>(null);
const editorError = ref('');
const activeEditor = ref<
    'mailSubject' | 'mailBody' | 'letterSubject' | 'letterBody'
>(props.activeTab === 'letters' ? 'letterBody' : 'mailBody');
const insertPlaceholder = (token: string) => {
    if (activeEditor.value === 'mailSubject') mailForm.subject += token;
    else if (activeEditor.value === 'mailBody')
        mailEditor.value?.insertText(token);
    else if (activeEditor.value === 'letterSubject') letter.subject += token;
    else letterEditor.value?.insertText(token);
};
const sendMails = () =>
    mailForm
        .transform((data) => ({ ...props.filters, ...data }))
        .post('/kommunikation/serienmails', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                mailForm.attachments = [];
                mailForm.confirmed = false;
            },
        });
const addAttachments = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const selected = Array.from(input.files ?? []);
    input.value = '';
    editorError.value = '';
    const allowed = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
        'text/csv',
        'image/png',
        'image/jpeg',
        'image/webp',
    ];
    const combined = [...mailForm.attachments, ...selected];
    if (combined.length > 5) {
        editorError.value = 'Es sind höchstens fünf Anhänge möglich.';
        return;
    }
    if (combined.some((file) => file.size > 2 * 1024 * 1024)) {
        editorError.value = 'Ein Anhang darf höchstens 2 MB groß sein.';
        return;
    }
    if (combined.some((file) => !allowed.includes(file.type))) {
        editorError.value =
            'Erlaubt sind PDF, DOCX, XLSX, CSV, TXT, PNG, JPEG und WebP.';
        return;
    }
    if (combined.reduce((sum, file) => sum + file.size, 0) > 5_000_000) {
        editorError.value =
            'Die Anhänge dürfen zusammen höchstens 5 MB groß sein.';
        return;
    }
    mailForm.attachments = combined;
};
const attachmentError = computed(() => {
    const errors = mailForm.errors as Record<string, string>;
    return (
        errors.attachments ??
        Object.entries(errors).find(([key]) =>
            key.startsWith('attachments.'),
        )?.[1]
    );
});
const formatBytes = (bytes: number) =>
    `${new Intl.NumberFormat('de-DE', { maximumFractionDigits: 1 }).format(bytes / 1024 / 1024)} MB`;
const number = new Intl.NumberFormat('de-DE');
const hasFilters = computed(
    () =>
        props.filters.status !== 'active' ||
        Object.entries(props.filters).some(
            ([key, value]) =>
                key !== 'status' && key !== 'custom' && value !== '',
        ) ||
        Object.keys(props.filters.custom).length > 0,
);
</script>

<template>
    <Head :title="pageCopy[activeTab].title" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">
                {{ pageCopy[activeTab].title }}
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ pageCopy[activeTab].subtitle }}
            </p>
        </div>
        <nav
            aria-label="Kommunikationsbereiche"
            class="flex flex-wrap gap-2 border-b pb-5"
        >
            <Link
                v-for="tab in tabs"
                :key="tab[0]"
                :href="tabUrl(tab[3])"
                :aria-current="activeTab === tab[0] ? 'page' : undefined"
                prefetch
                class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:bg-muted"
                :class="
                    activeTab === tab[0]
                        ? 'bg-muted text-foreground'
                        : 'text-muted-foreground'
                "
                ><component :is="tab[2]" class="size-4" />{{ tab[1] }}</Link
            >
        </nav>

        <template v-if="activeTab !== 'history'">
            <RecipientFilter
                :draft="filterDraft"
                :filter-options="filterOptions"
                :custom-filters="customFilters"
                :has-filters="hasFilters"
                @apply="applyFilters"
                @reset="resetFilters"
            />

            <RecipientPreview :summary="summary" :preview="preview" />

            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
                <Card v-if="activeTab === 'mail'" class="gap-0 py-0">
                    <CardHeader class="border-b py-5"
                        ><CardTitle class="text-base"
                            >E-Mail verfassen</CardTitle
                        ><CardDescription
                            >Absender: {{ mailConfiguration.from_name }} &lt;{{
                                mailConfiguration.from_address
                            }}&gt;</CardDescription
                        ></CardHeader
                    >
                    <CardContent class="py-5"
                        ><form class="space-y-5" @submit.prevent="sendMails">
                            <Alert
                                v-if="mailConfiguration.driver === 'log'"
                                variant="warning"
                            >
                                <AlertTriangle />
                                <AlertDescription>
                                    Der Mailtransport steht auf „Log“.
                                    Nachrichten werden protokolliert, aber nicht
                                    an echte Postfächer zugestellt.
                                </AlertDescription>
                            </Alert>
                            <InputError :message="mailRecipientError" />
                            <div class="grid gap-2">
                                <Label for="mail-subject">Betreff</Label
                                ><Input
                                    id="mail-subject"
                                    v-model="mailForm.subject"
                                    maxlength="180"
                                    required
                                    @focus="activeEditor = 'mailSubject'"
                                /><InputError
                                    :message="mailForm.errors.subject"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="mail-body">Nachricht</Label>
                                <RichTextEditor
                                    id="mail-body"
                                    ref="mailEditor"
                                    v-model="mailForm.body"
                                    @focus="activeEditor = 'mailBody'"
                                    @error="editorError = $event"
                                />
                                <p class="text-xs text-muted-foreground">
                                    Formatierungen und eingebettete Bilder
                                    erscheinen direkt in der E-Mail.
                                </p>
                                <InputError :message="mailForm.errors.body" />
                            </div>
                            <div class="grid gap-2">
                                <Label>Anhänge</Label>
                                <label
                                    class="flex cursor-pointer items-center justify-center gap-2 rounded-md border border-dashed px-4 py-4 text-sm text-muted-foreground hover:bg-muted/50"
                                >
                                    <Paperclip class="size-4" /> Dateien
                                    auswählen
                                    <input
                                        type="file"
                                        multiple
                                        accept=".pdf,.docx,.xlsx,.csv,.txt,.png,.jpg,.jpeg,.webp"
                                        class="hidden"
                                        @change="addAttachments"
                                    />
                                </label>
                                <div
                                    v-if="mailForm.attachments.length"
                                    class="space-y-2"
                                >
                                    <div
                                        v-for="(
                                            file, index
                                        ) in mailForm.attachments"
                                        :key="`${file.name}-${file.size}-${index}`"
                                        class="flex items-center gap-3 rounded-md border px-3 py-2 text-sm"
                                    >
                                        <Paperclip
                                            class="size-4 shrink-0 text-muted-foreground"
                                        />
                                        <span class="min-w-0 flex-1 truncate">{{
                                            file.name
                                        }}</span>
                                        <span
                                            class="text-xs text-muted-foreground"
                                            >{{ formatBytes(file.size) }}</span
                                        >
                                        <button
                                            type="button"
                                            class="rounded p-1 hover:bg-muted"
                                            :aria-label="`${file.name} entfernen`"
                                            @click="
                                                mailForm.attachments.splice(
                                                    index,
                                                    1,
                                                )
                                            "
                                        >
                                            <X class="size-4" />
                                        </button>
                                    </div>
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    Bis zu 5 Dateien, je 2 MB und zusammen
                                    höchstens 5 MB.
                                </p>
                                <InputError
                                    :message="editorError || attachmentError"
                                />
                            </div>
                            <label
                                class="flex items-start gap-3 rounded-lg border p-3 text-sm"
                                ><input
                                    v-model="mailForm.confirmed"
                                    type="checkbox"
                                    class="mt-0.5 size-4"
                                    required
                                /><span
                                    >Ich habe Empfängerauswahl, Betreff und
                                    Nachricht geprüft und bestätige den Versand
                                    an
                                    <strong>{{
                                        formatNumber(summary.with_email)
                                    }}</strong>
                                    Empfänger mit E-Mail-Adresse.</span
                                ></label
                            ><InputError
                                :message="mailForm.errors.confirmed"
                            /><Button
                                type="submit"
                                :disabled="
                                    mailForm.processing ||
                                    summary.with_email === 0
                                "
                                ><Send />
                                {{
                                    mailForm.processing
                                        ? 'Wird versendet …'
                                        : 'Serien-E-Mails versenden'
                                }}</Button
                            >
                        </form></CardContent
                    >
                </Card>

                <Card v-else class="gap-0 py-0">
                    <CardHeader class="border-b py-5"
                        ><CardTitle class="text-base"
                            >Serienbrief verfassen</CardTitle
                        ><CardDescription
                            >Jeder Brief beginnt auf einer eigenen
                            A4-Seite.</CardDescription
                        ></CardHeader
                    >
                    <CardContent class="py-5"
                        ><form
                            method="post"
                            action="/kommunikation/serienbriefe"
                            class="space-y-5"
                        >
                            <input
                                type="hidden"
                                name="_token"
                                :value="csrfToken"
                            /><template
                                v-for="(value, key) in filters"
                                :key="key"
                                ><template v-if="key === 'custom'"
                                    ><input
                                        v-for="(
                                            customValue, customKey
                                        ) in value"
                                        :key="customKey"
                                        type="hidden"
                                        :name="`custom[${customKey}]`"
                                        :value="customValue" /></template
                                ><input
                                    v-else
                                    type="hidden"
                                    :name="key"
                                    :value="value" /></template
                            ><InputError :message="serverErrors.recipients" />
                            <div class="grid gap-2">
                                <Label for="letter-subject">Betreff</Label
                                ><Input
                                    id="letter-subject"
                                    v-model="letter.subject"
                                    name="subject"
                                    maxlength="180"
                                    required
                                    @focus="activeEditor = 'letterSubject'"
                                /><InputError :message="serverErrors.subject" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="letter-body">Brieftext</Label>
                                <RichTextEditor
                                    id="letter-body"
                                    ref="letterEditor"
                                    v-model="letter.body"
                                    @focus="activeEditor = 'letterBody'"
                                    @error="editorError = $event"
                                />
                                <input
                                    type="hidden"
                                    name="body"
                                    :value="letter.body"
                                />
                                <p class="text-xs text-muted-foreground">
                                    Formatierungen und eingebettete Bilder
                                    werden in die PDF-Briefe übernommen.
                                </p>
                                <InputError
                                    :message="editorError || serverErrors.body"
                                />
                            </div>
                            <fieldset class="space-y-2">
                                <legend class="text-sm font-medium">
                                    Downloadformat
                                </legend>
                                <label
                                    class="flex items-start gap-3 rounded-lg border p-3 text-sm"
                                    ><input
                                        v-model="letter.format"
                                        type="radio"
                                        name="format"
                                        value="pdf"
                                        class="mt-0.5 size-4"
                                    /><span
                                        ><strong>Ein Gesamt-PDF</strong
                                        ><br /><span
                                            class="text-muted-foreground"
                                            >Alle Briefe hintereinander in einer
                                            Datei.</span
                                        ></span
                                    ></label
                                ><label
                                    class="flex items-start gap-3 rounded-lg border p-3 text-sm"
                                    ><input
                                        v-model="letter.format"
                                        type="radio"
                                        name="format"
                                        value="zip"
                                        class="mt-0.5 size-4"
                                    /><span
                                        ><strong>ZIP mit Einzel-PDFs</strong
                                        ><br /><span
                                            class="text-muted-foreground"
                                            >Eine benannte PDF-Datei pro
                                            Mitglied.</span
                                        ></span
                                    ></label
                                >
                            </fieldset>
                            <label
                                class="flex items-start gap-3 rounded-lg border p-3 text-sm"
                                ><input
                                    v-model="letter.confirmed"
                                    type="checkbox"
                                    name="confirmed"
                                    value="1"
                                    class="mt-0.5 size-4"
                                    required
                                /><span
                                    >Ich habe Auswahl und Brieftext geprüft. Es
                                    werden
                                    <strong>{{
                                        formatNumber(summary.total)
                                    }}</strong>
                                    personalisierte Briefe erzeugt.</span
                                ></label
                            ><InputError
                                :message="serverErrors.confirmed"
                            /><Button
                                type="submit"
                                :disabled="summary.total === 0"
                                ><Download /> Serienbriefe herunterladen</Button
                            >
                        </form></CardContent
                    >
                </Card>

                <Card class="h-fit gap-0 py-0 lg:sticky lg:top-6"
                    ><CardHeader class="border-b py-5"
                        ><CardTitle class="text-base">Platzhalter</CardTitle
                        ><CardDescription
                            >Ein Klick fügt den Wert in das zuletzt aktive
                            Textfeld ein.</CardDescription
                        ></CardHeader
                    ><CardContent
                        class="max-h-[560px] space-y-5 overflow-y-auto py-5"
                        ><div
                            v-for="group in ['Mitglied', 'Verein', 'Allgemein']"
                            :key="group"
                        >
                            <h3
                                class="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                {{ group }}
                            </h3>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="item in placeholders.filter(
                                        (entry) => entry.group === group,
                                    )"
                                    :key="item.token"
                                    type="button"
                                    class="rounded-md border bg-background px-2 py-1 text-left text-xs hover:bg-muted"
                                    :title="item.token"
                                    @click="insertPlaceholder(item.token)"
                                >
                                    {{ item.label }}
                                </button>
                            </div>
                        </div></CardContent
                    ></Card
                >
            </div>
        </template>

        <template v-else>
            <CommunicationHistory
                :campaigns="campaigns"
                :deliveries="deliveries"
                :selected-campaign="selectedCampaign"
            />
        </template>
    </div>
</template>
