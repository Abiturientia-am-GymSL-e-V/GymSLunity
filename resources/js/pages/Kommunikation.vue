<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Archive,
    AtSign,
    Download,
    FileText,
    History,
    Mail,
    MapPin,
    Paperclip,
    RotateCcw,
    Search,
    Send,
    UsersRound,
    X,
} from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import RichTextEditor from '@/components/communication/RichTextEditor.vue';
import { Badge } from '@/components/ui/badge';
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
import type { MemberField } from '@/types/members';

type Tab = 'mail' | 'letters' | 'history';
type Filters = {
    q: string;
    status: string;
    membership: string;
    department_role: string;
    club_role: string;
    gender: string;
    payment_method: string;
    city: string;
    honorary: string;
    email_status: string;
    address_status: string;
    joined_from: string;
    joined_to: string;
    custom: Record<string, string>;
};
type OptionSet = {
    memberships: string[];
    departmentRoles: string[];
    clubRoles: string[];
    paymentMethods: string[];
    cities: string[];
};
type Preview = {
    member_number: number;
    name: string;
    email: string | null;
    city: string | null;
    membership_type: string;
    email_ready: boolean;
    address_ready: boolean;
};
type Campaign = {
    id: number;
    kind: 'mail' | 'letter';
    format: string | null;
    subject: string;
    recipient_count: number;
    skipped_count: number;
    success_count: number;
    failure_count: number;
    created_by_name: string;
    created_at: string;
    attachments: { name: string; mime: string; size: number }[];
};
type Delivery = {
    id: number;
    member_number: number;
    recipient_name: string;
    recipient_email: string | null;
    status: 'pending' | 'sent' | 'failed' | 'generated';
    error: string | null;
};

const props = defineProps<{
    activeTab: Tab;
    filters: Filters;
    filterOptions: OptionSet;
    customFilters: MemberField[];
    summary: {
        total: number;
        with_email: number;
        without_email: number;
        complete_address: number;
        incomplete_address: number;
    };
    preview: Preview[];
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
const pageCopy: Record<Tab, { title: string; subtitle: string }> = {
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
const filterDraft = reactive<Filters>({
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
const dateTime = (value: string) =>
    new Intl.DateTimeFormat('de-DE', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value.replace(' ', 'T')));
const hasFilters = computed(
    () =>
        props.filters.status !== 'active' ||
        Object.entries(props.filters).some(
            ([key, value]) =>
                key !== 'status' && key !== 'custom' && value !== '',
        ) ||
        Object.keys(props.filters.custom).length > 0,
);
const selectClass =
    'h-9 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus:border-ring focus:ring-2 focus:ring-ring/30';
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
            <section
                class="rounded-xl border bg-card p-4 sm:p-5"
                aria-label="Empfänger filtern"
            >
                <div class="flex flex-wrap items-center gap-3">
                    <div class="relative min-w-0 flex-1 basis-72 sm:max-w-md">
                        <Label for="communication-search" class="sr-only"
                            >Empfänger suchen</Label
                        ><Search
                            class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                        /><Input
                            id="communication-search"
                            v-model="filterDraft.q"
                            type="search"
                            maxlength="120"
                            placeholder="Name, Mitgliedsnummer, E-Mail oder Ort …"
                            class="pl-9"
                            @keydown.enter.prevent="applyFilters"
                        />
                    </div>
                    <Button variant="outline" @click="applyFilters"
                        >Filter anwenden</Button
                    ><Button
                        v-if="hasFilters"
                        variant="ghost"
                        @click="resetFilters"
                        ><RotateCcw /> Zurücksetzen</Button
                    >
                </div>
                <div
                    class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
                >
                    <div class="grid gap-2">
                        <Label for="filter-status">Mitgliedsstatus</Label
                        ><select
                            id="filter-status"
                            v-model="filterDraft.status"
                            :class="selectClass"
                        >
                            <option value="active">Aktive Mitglieder</option>
                            <option value="contacts">Kontakte</option>
                            <option value="former">
                                Ausgetreten / verstorben
                            </option>
                            <option value="future">Künftige Eintritte</option>
                            <option value="all">Alle Datensätze</option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="filter-membership">Mitgliedsart</Label
                        ><select
                            id="filter-membership"
                            v-model="filterDraft.membership"
                            :class="selectClass"
                        >
                            <option value="">Alle</option>
                            <option
                                v-for="item in filterOptions.memberships"
                                :key="item"
                                :value="item"
                            >
                                {{ item }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="filter-department">Funktion Abteilung</Label
                        ><select
                            id="filter-department"
                            v-model="filterDraft.department_role"
                            :class="selectClass"
                        >
                            <option value="">Alle</option>
                            <option value="__any__">Mit Funktion</option>
                            <option value="__none__">Ohne Funktion</option>
                            <option
                                v-for="item in filterOptions.departmentRoles"
                                :key="item"
                                :value="item"
                            >
                                {{ item }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="filter-club-role"
                            >Funktion Hauptverein</Label
                        ><select
                            id="filter-club-role"
                            v-model="filterDraft.club_role"
                            :class="selectClass"
                        >
                            <option value="">Alle</option>
                            <option value="__any__">Mit Funktion</option>
                            <option value="__none__">Ohne Funktion</option>
                            <option
                                v-for="item in filterOptions.clubRoles"
                                :key="item"
                                :value="item"
                            >
                                {{ item }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="filter-gender">Geschlecht</Label
                        ><select
                            id="filter-gender"
                            v-model="filterDraft.gender"
                            :class="selectClass"
                        >
                            <option value="">Alle</option>
                            <option value="w">Weiblich</option>
                            <option value="m">Männlich</option>
                            <option value="d">Divers</option>
                            <option value="o">Ohne Angabe</option>
                            <option value="__none__">Nicht hinterlegt</option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="filter-payment">Zahlungsart</Label
                        ><select
                            id="filter-payment"
                            v-model="filterDraft.payment_method"
                            :class="selectClass"
                        >
                            <option value="">Alle</option>
                            <option
                                v-for="item in filterOptions.paymentMethods"
                                :key="item"
                                :value="item"
                            >
                                {{ item }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="filter-city">Wohnort</Label
                        ><select
                            id="filter-city"
                            v-model="filterDraft.city"
                            :class="selectClass"
                        >
                            <option value="">Alle</option>
                            <option
                                v-for="item in filterOptions.cities"
                                :key="item"
                                :value="item"
                            >
                                {{ item }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="filter-honorary">Ehrenmitglied</Label
                        ><select
                            id="filter-honorary"
                            v-model="filterDraft.honorary"
                            :class="selectClass"
                        >
                            <option value="">Alle</option>
                            <option value="yes">Ja</option>
                            <option value="no">Nein</option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="filter-email">E-Mail-Adresse</Label
                        ><select
                            id="filter-email"
                            v-model="filterDraft.email_status"
                            :class="selectClass"
                        >
                            <option value="">Alle</option>
                            <option value="with">Vorhanden</option>
                            <option value="without">Fehlt</option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="filter-address">Postanschrift</Label
                        ><select
                            id="filter-address"
                            v-model="filterDraft.address_status"
                            :class="selectClass"
                        >
                            <option value="">Alle</option>
                            <option value="complete">Vollständig</option>
                            <option value="incomplete">Unvollständig</option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="filter-joined-from">Eintritt von</Label
                        ><Input
                            id="filter-joined-from"
                            v-model="filterDraft.joined_from"
                            type="date"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="filter-joined-to">Eintritt bis</Label
                        ><Input
                            id="filter-joined-to"
                            v-model="filterDraft.joined_to"
                            type="date"
                            :min="filterDraft.joined_from"
                        />
                    </div>
                    <div
                        v-for="field in customFilters"
                        :key="field.key"
                        class="grid gap-2"
                    >
                        <Label :for="`filter-${field.key}`">{{
                            field.label
                        }}</Label
                        ><select
                            v-if="
                                field.type === 'select' ||
                                field.type === 'boolean'
                            "
                            :id="`filter-${field.key}`"
                            v-model="filterDraft.custom[field.key]"
                            :class="selectClass"
                        >
                            <option value="">Alle</option>
                            <template v-if="field.type === 'boolean'"
                                ><option value="1">Ja</option>
                                <option value="0">Nein</option></template
                            >
                            <option
                                v-for="(label, value) in field.options"
                                v-else
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option></select
                        ><Input
                            v-else
                            :id="`filter-${field.key}`"
                            v-model="filterDraft.custom[field.key]"
                            :type="
                                field.type === 'date'
                                    ? 'date'
                                    : ['number', 'decimal'].includes(field.type)
                                      ? 'number'
                                      : 'text'
                            "
                            :step="
                                field.type === 'decimal' ? '0.01' : undefined
                            "
                            placeholder="Alle"
                        />
                    </div>
                </div>
            </section>

            <div class="grid gap-4 sm:grid-cols-3">
                <Card class="gap-2 py-5"
                    ><CardHeader class="px-5 pb-0"
                        ><div class="flex items-center justify-between">
                            <CardDescription
                                >Gefilterte Empfänger</CardDescription
                            ><UsersRound class="size-5 text-muted-foreground" />
                        </div>
                        <CardTitle class="text-3xl tabular-nums">{{
                            number.format(summary.total)
                        }}</CardTitle></CardHeader
                    ><CardContent class="px-5 text-xs text-muted-foreground"
                        >Die ersten 50 werden unten angezeigt.</CardContent
                    ></Card
                >
                <Card class="gap-2 py-5"
                    ><CardHeader class="px-5 pb-0"
                        ><div class="flex items-center justify-between">
                            <CardDescription>Mit E-Mail-Adresse</CardDescription
                            ><AtSign class="size-5 text-muted-foreground" />
                        </div>
                        <CardTitle class="text-3xl tabular-nums">{{
                            number.format(summary.with_email)
                        }}</CardTitle></CardHeader
                    ><CardContent class="px-5 text-xs text-muted-foreground"
                        >{{ number.format(summary.without_email) }} ohne
                        E-Mail-Adresse</CardContent
                    ></Card
                >
                <Card class="gap-2 py-5"
                    ><CardHeader class="px-5 pb-0"
                        ><div class="flex items-center justify-between">
                            <CardDescription
                                >Vollständige Anschrift</CardDescription
                            ><MapPin class="size-5 text-muted-foreground" />
                        </div>
                        <CardTitle class="text-3xl tabular-nums">{{
                            number.format(summary.complete_address)
                        }}</CardTitle></CardHeader
                    ><CardContent class="px-5 text-xs text-muted-foreground"
                        >{{
                            number.format(summary.incomplete_address)
                        }}
                        unvollständig</CardContent
                    ></Card
                >
            </div>

            <Card class="gap-0 overflow-hidden py-0">
                <CardHeader class="border-b py-5"
                    ><CardTitle class="text-base">Empfängervorschau</CardTitle
                    ><CardDescription
                        >Kontrolliere die Auswahl, bevor du versendest oder PDFs
                        erzeugst.</CardDescription
                    ></CardHeader
                >
                <CardContent class="overflow-x-auto p-0"
                    ><table class="w-full min-w-[720px] text-sm">
                        <thead
                            class="bg-muted/50 text-left text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">Nr.</th>
                                <th class="px-3 py-3 font-medium">Name</th>
                                <th class="px-3 py-3 font-medium">
                                    Mitgliedsart
                                </th>
                                <th class="px-3 py-3 font-medium">E-Mail</th>
                                <th class="px-5 py-3 font-medium">Anschrift</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="member in preview"
                                :key="member.member_number"
                            >
                                <td class="px-5 py-3 tabular-nums">
                                    {{ member.member_number }}
                                </td>
                                <td class="px-3 py-3 font-medium">
                                    {{ member.name }}
                                </td>
                                <td class="px-3 py-3">
                                    {{ member.membership_type }}
                                </td>
                                <td class="px-3 py-3">
                                    <span
                                        :class="
                                            member.email_ready
                                                ? ''
                                                : 'text-amber-700'
                                        "
                                        >{{ member.email || 'Fehlt' }}</span
                                    >
                                </td>
                                <td class="px-5 py-3">
                                    <Badge
                                        :variant="
                                            member.address_ready
                                                ? 'outline'
                                                : 'secondary'
                                        "
                                        >{{
                                            member.address_ready
                                                ? member.city
                                                : 'Unvollständig'
                                        }}</Badge
                                    >
                                </td>
                            </tr>
                            <tr v-if="preview.length === 0">
                                <td
                                    colspan="5"
                                    class="px-5 py-8 text-center text-muted-foreground"
                                >
                                    Keine Empfänger für diese Filter.
                                </td>
                            </tr>
                        </tbody>
                    </table></CardContent
                >
            </Card>

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
                            <div
                                v-if="mailConfiguration.driver === 'log'"
                                class="flex gap-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200"
                            >
                                <AlertTriangle class="mt-0.5 size-5 shrink-0" />
                                <p>
                                    Der Mailtransport steht auf „Log“.
                                    Nachrichten werden protokolliert, aber nicht
                                    an echte Postfächer zugestellt.
                                </p>
                            </div>
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
                                        number.format(summary.with_email)
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
                                        number.format(summary.total)
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
            <Card class="gap-0 overflow-hidden py-0"
                ><CardHeader class="border-b py-5"
                    ><CardTitle class="text-base">Letzte Vorgänge</CardTitle
                    ><CardDescription
                        >Protokollierte Serienmail-Versände und
                        Briefexporte.</CardDescription
                    ></CardHeader
                ><CardContent class="overflow-x-auto p-0"
                    ><table class="w-full min-w-[860px] text-sm">
                        <thead
                            class="bg-muted/50 text-left text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">Zeitpunkt</th>
                                <th class="px-3 py-3 font-medium">Art</th>
                                <th class="px-3 py-3 font-medium">Betreff</th>
                                <th class="px-3 py-3 text-right font-medium">
                                    Erfolgreich
                                </th>
                                <th class="px-3 py-3 text-right font-medium">
                                    Übersprungen
                                </th>
                                <th class="px-3 py-3 text-right font-medium">
                                    Fehler
                                </th>
                                <th class="px-3 py-3 font-medium">
                                    Erstellt von
                                </th>
                                <th class="px-5 py-3 text-right font-medium">
                                    Details
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="campaign in campaigns"
                                :key="campaign.id"
                            >
                                <td class="px-5 py-3 whitespace-nowrap">
                                    {{ dateTime(campaign.created_at) }}
                                </td>
                                <td class="px-3 py-3">
                                    <Badge variant="outline"
                                        ><Mail
                                            v-if="campaign.kind === 'mail'"
                                            class="mr-1 size-3"
                                        /><Archive
                                            v-else
                                            class="mr-1 size-3"
                                        />{{
                                            campaign.kind === 'mail'
                                                ? 'E-Mail'
                                                : campaign.format?.toUpperCase()
                                        }}</Badge
                                    >
                                </td>
                                <td
                                    class="max-w-sm truncate px-3 py-3 font-medium"
                                >
                                    {{ campaign.subject }}
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums">
                                    {{ number.format(campaign.success_count) }}
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums">
                                    {{ number.format(campaign.skipped_count) }}
                                </td>
                                <td
                                    class="px-3 py-3 text-right tabular-nums"
                                    :class="
                                        campaign.failure_count
                                            ? 'text-destructive'
                                            : ''
                                    "
                                >
                                    {{ number.format(campaign.failure_count) }}
                                </td>
                                <td class="px-3 py-3">
                                    {{ campaign.created_by_name }}
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <Link
                                        :href="`/kommunikation/verlauf?campaign=${campaign.id}`"
                                        class="font-medium text-primary underline-offset-4 hover:underline"
                                        >Anzeigen</Link
                                    >
                                </td>
                            </tr>
                            <tr v-if="campaigns.length === 0">
                                <td
                                    colspan="8"
                                    class="px-5 py-10 text-center text-muted-foreground"
                                >
                                    Noch keine Kommunikationsvorgänge vorhanden.
                                </td>
                            </tr>
                        </tbody>
                    </table></CardContent
                ></Card
            >

            <Card v-if="selectedCampaign" class="gap-0 overflow-hidden py-0"
                ><CardHeader class="border-b py-5"
                    ><CardTitle class="text-base"
                        >Vorgang #{{ selectedCampaign.id }}:
                        {{ selectedCampaign.subject }}</CardTitle
                    ><CardDescription
                        >{{ number.format(selectedCampaign.recipient_count) }}
                        Empfänger · erstellt von
                        {{ selectedCampaign.created_by_name }} am
                        {{ dateTime(selectedCampaign.created_at)
                        }}<template v-if="selectedCampaign.attachments.length">
                            · {{ selectedCampaign.attachments.length }}
                            {{
                                selectedCampaign.attachments.length === 1
                                    ? 'Anhang'
                                    : 'Anhänge'
                            }}:
                            {{
                                selectedCampaign.attachments
                                    .map((file) => file.name)
                                    .join(', ')
                            }}
                        </template></CardDescription
                    ></CardHeader
                ><CardContent class="overflow-x-auto p-0"
                    ><table class="w-full min-w-[720px] text-sm">
                        <thead
                            class="bg-muted/50 text-left text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">Nr.</th>
                                <th class="px-3 py-3 font-medium">Empfänger</th>
                                <th class="px-3 py-3 font-medium">E-Mail</th>
                                <th class="px-3 py-3 font-medium">Status</th>
                                <th class="px-5 py-3 font-medium">Hinweis</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="delivery in deliveries"
                                :key="delivery.id"
                            >
                                <td class="px-5 py-3 tabular-nums">
                                    {{ delivery.member_number }}
                                </td>
                                <td class="px-3 py-3 font-medium">
                                    {{ delivery.recipient_name }}
                                </td>
                                <td class="px-3 py-3">
                                    {{ delivery.recipient_email || '–' }}
                                </td>
                                <td class="px-3 py-3">
                                    <Badge
                                        :variant="
                                            delivery.status === 'failed'
                                                ? 'destructive'
                                                : 'outline'
                                        "
                                        >{{
                                            delivery.status === 'sent'
                                                ? 'Versendet'
                                                : delivery.status ===
                                                    'generated'
                                                  ? 'Erzeugt'
                                                  : delivery.status === 'failed'
                                                    ? 'Fehlgeschlagen'
                                                    : 'Ausstehend'
                                        }}</Badge
                                    >
                                </td>
                                <td class="px-5 py-3 text-muted-foreground">
                                    {{ delivery.error || '–' }}
                                </td>
                            </tr>
                        </tbody>
                    </table></CardContent
                ></Card
            >
        </template>
    </div>
</template>
