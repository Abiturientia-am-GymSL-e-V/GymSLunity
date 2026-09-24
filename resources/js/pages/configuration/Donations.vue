<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import ConfigurationNav from '@/components/configuration/ConfigurationNav.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Settings = {
    donation_purpose_codes?: string[];
    contributions_tax_deductible?: boolean;
    tax_privilege_notice_type?: string;
    tax_privilege_notice_date?: string;
    tax_privilege_assessment_period?: string;
    certificate_location?: string;
    certificate_machine_generated_notified?: boolean;
};
const props = defineProps<{
    settings: Settings;
    version: number;
    purposes: Array<{ value: string; label: string }>;
    club: Record<string, string | null>;
    readiness: string[];
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Konfiguration', href: '/konfiguration/verein' },
            { title: 'Spenden' },
        ],
    },
});
const form = useForm({
    version: props.version,
    donation_purpose_codes: props.settings.donation_purpose_codes ?? [],
    contributions_tax_deductible:
        props.settings.contributions_tax_deductible ?? false,
    tax_privilege_notice_type:
        props.settings.tax_privilege_notice_type ?? 'exemption_notice',
    tax_privilege_notice_date: props.settings.tax_privilege_notice_date ?? '',
    tax_privilege_assessment_period:
        props.settings.tax_privilege_assessment_period ?? '',
    certificate_location:
        props.settings.certificate_location ?? props.club.city ?? '',
    certificate_machine_generated_notified:
        props.settings.certificate_machine_generated_notified ?? false,
});
function save() {
    form.patch('/konfiguration/spenden', {
        preserveScroll: true,
        onSuccess: () => {
            form.version = props.version;
            form.defaults();
        },
    });
}
function purposeReference(value: string) {
    if (value.startsWith('52-'))
        return `§ 52 Abs. 2 Satz 1 Nr. ${value.slice(3)} AO`;
    if (value.startsWith('53-')) return '§ 53 AO';
    return '§ 54 AO';
}
const identityRows = [
    ['Verein', 'name'],
    ['Anschrift', 'street'],
    ['PLZ / Ort', 'postal_code'],
    ['Registergericht', 'register_court'],
    ['Vereinsregisternummer', 'register_number'],
    ['Finanzamt', 'tax_office'],
    ['Steuernummer', 'tax_number'],
] as const;
</script>

<template>
    <Head title="Spenden konfigurieren" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Konfiguration</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Stammdaten für Spenden und Zuwendungsbestätigungen.
            </p>
        </div>
        <ConfigurationNav />

        <div
            v-if="readiness.length"
            class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100"
        >
            <p class="font-medium">Noch nicht ausstellungsbereit</p>
            <p>{{ readiness.join(' ') }}</p>
        </div>

        <section class="rounded-xl border bg-card">
            <div
                class="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4"
            >
                <div>
                    <h2 class="font-semibold">Übernommene Vereinsdaten</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Diese Angaben stammen aus „Vereinsdaten“ und werden beim
                        Ausstellen unveränderlich in den Beleg übernommen.
                    </p>
                </div>
                <a
                    href="/konfiguration/verein"
                    class="text-sm font-medium text-primary hover:underline"
                    >Vereinsdaten bearbeiten</a
                >
            </div>
            <dl class="grid gap-x-8 gap-y-3 p-5 text-sm sm:grid-cols-2">
                <div
                    v-for="row in identityRows"
                    :key="row[1]"
                    class="grid grid-cols-[9rem_1fr] gap-3"
                >
                    <dt class="text-muted-foreground">{{ row[0] }}</dt>
                    <dd class="font-medium">
                        {{
                            row[1] === 'postal_code'
                                ? [club.postal_code, club.city]
                                      .filter(Boolean)
                                      .join(' ') || '–'
                                : club[row[1]] || '–'
                        }}
                    </dd>
                </div>
            </dl>
        </section>

        <form class="space-y-5" novalidate @submit.prevent="save">
            <InputError :message="form.errors.version" />
            <section class="rounded-xl border bg-card">
                <div class="border-b px-5 py-4">
                    <h2 class="font-semibold">Steuerlicher Bescheid</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Bitte exakt entsprechend dem aktuell gültigen Bescheid
                        des Finanzamts erfassen.
                    </p>
                </div>
                <div class="grid gap-5 p-5 sm:grid-cols-2">
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="notice-type">Bescheidart *</Label>
                        <select
                            id="notice-type"
                            v-model="form.tax_privilege_notice_type"
                            class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="exemption_notice">
                                Freistellungsbescheid
                            </option>
                            <option value="corporate_tax_attachment">
                                Anlage zum Körperschaftsteuerbescheid
                            </option>
                            <option value="section_60a_notice">
                                Bescheid über die gesonderte Feststellung nach §
                                60a AO
                            </option>
                        </select>
                        <InputError
                            :message="form.errors.tax_privilege_notice_type"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="notice-date">Bescheiddatum *</Label>
                        <Input
                            id="notice-date"
                            v-model="form.tax_privilege_notice_date"
                            type="date"
                            required
                        />
                        <InputError
                            :message="form.errors.tax_privilege_notice_date"
                        />
                    </div>
                    <div
                        v-if="
                            form.tax_privilege_notice_type !==
                            'section_60a_notice'
                        "
                        class="space-y-2"
                    >
                        <Label for="assessment-period"
                            >Letzter Veranlagungszeitraum *</Label
                        >
                        <Input
                            id="assessment-period"
                            v-model="form.tax_privilege_assessment_period"
                            placeholder="z. B. 2023"
                            maxlength="30"
                            required
                        />
                        <InputError
                            :message="
                                form.errors.tax_privilege_assessment_period
                            "
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="certificate-location"
                            >Ausstellungsort *</Label
                        >
                        <Input
                            id="certificate-location"
                            v-model="form.certificate_location"
                            maxlength="255"
                            required
                        />
                        <InputError
                            :message="form.errors.certificate_location"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="membership-fees"
                            >Mitgliedsbeiträge abzugsfähig</Label
                        >
                        <select
                            id="membership-fees"
                            v-model="form.contributions_tax_deductible"
                            class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option :value="false">Nein</option>
                            <option :value="true">Ja</option>
                        </select>
                        <p class="text-xs text-muted-foreground">
                            Insbesondere bei Sport, freizeitnaher Kultur,
                            Heimatpflege und Zwecken nach § 52 Abs. 2 Satz 1 Nr.
                            23 AO sind Mitgliedsbeiträge regelmäßig nicht
                            abziehbar.
                        </p>
                    </div>
                    <label
                        class="flex items-start gap-3 rounded-lg border p-4 sm:col-span-2"
                    >
                        <input
                            v-model="
                                form.certificate_machine_generated_notified
                            "
                            type="checkbox"
                            class="mt-1 size-4 rounded border-input"
                        />
                        <span
                            ><span class="block text-sm font-medium"
                                >Verfahren für maschinell erstellte
                                Bestätigungen wurde dem Finanzamt
                                angezeigt</span
                            ><span
                                class="mt-1 block text-xs text-muted-foreground"
                                >Dokumentiert die organisatorische Voraussetzung
                                für maschinell erstellte Bestätigungen ohne
                                eigenhändige Unterschrift. Die Anwendung
                                versieht jeden Beleg zusätzlich mit einer
                                digitalen Freigabe.</span
                            ></span
                        >
                    </label>
                </div>
            </section>

            <section class="rounded-xl border bg-card">
                <div class="border-b px-5 py-4">
                    <h2 class="font-semibold">Steuerbegünstigte Zwecke</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Nur die im gültigen Bescheid bzw. in der Satzung
                        bestätigten Zwecke auswählen.
                    </p>
                </div>
                <div class="grid gap-3 p-5 sm:grid-cols-2">
                    <label
                        v-for="purpose in purposes"
                        :key="purpose.value"
                        class="flex items-start gap-3 rounded-lg border p-3 text-sm hover:bg-muted/40"
                    >
                        <input
                            v-model="form.donation_purpose_codes"
                            type="checkbox"
                            :value="purpose.value"
                            class="mt-0.5 size-4 rounded border-input"
                        />
                        <span
                            ><span
                                class="font-mono text-xs text-muted-foreground"
                                >{{ purposeReference(purpose.value) }}</span
                            ><span class="mt-0.5 block">{{
                                purpose.label
                            }}</span></span
                        >
                    </label>
                </div>
                <InputError
                    class="px-5 pb-5"
                    :message="form.errors.donation_purpose_codes"
                />
            </section>

            <div
                class="sticky bottom-0 flex justify-end border-t bg-background/95 py-3 backdrop-blur"
            >
                <Button :disabled="form.processing"
                    ><Spinner v-if="form.processing" /><Save
                        v-else
                        class="size-4"
                    />Spenden-Stammdaten speichern</Button
                >
            </div>
        </form>
    </div>
</template>
