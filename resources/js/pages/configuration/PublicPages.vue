<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import ConfigurationNav from '@/components/configuration/ConfigurationNav.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

type TemplateKey =
    | 'imprint_text'
    | 'privacy_text'
    | 'member_access_mail_subject'
    | 'member_access_mail_text'
    | 'join_mail_subject'
    | 'join_mail_text'
    | 'welcome_mail_subject'
    | 'welcome_mail_text'
    | 'contribution_invoice_mail_subject'
    | 'contribution_invoice_mail_text';

const props = defineProps<{
    settings: Record<TemplateKey, string>;
    defaults: Record<TemplateKey, string>;
    placeholders: string[];
    version: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Konfiguration', href: '/konfiguration/verein' },
            { title: 'Startseite' },
        ],
    },
});

const form = useForm({ ...props.settings, version: props.version });
const legal: { key: TemplateKey; label: string; rows: number }[] = [
    { key: 'imprint_text', label: 'Impressum', rows: 13 },
    { key: 'privacy_text', label: 'Datenschutzerklärung', rows: 18 },
];
const mails: { subject: TemplateKey; text: TemplateKey; label: string }[] = [
    {
        subject: 'member_access_mail_subject',
        text: 'member_access_mail_text',
        label: 'Mitgliederzugang',
    },
    {
        subject: 'join_mail_subject',
        text: 'join_mail_text',
        label: 'Mitglied werden',
    },
    {
        subject: 'welcome_mail_subject',
        text: 'welcome_mail_text',
        label: 'Willkommen nach dem Mitgliedsantrag',
    },
    {
        subject: 'contribution_invoice_mail_subject',
        text: 'contribution_invoice_mail_text',
        label: 'Beitragsrechnung',
    },
];
</script>

<template>
    <Head title="Startseite konfigurieren" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Konfiguration</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Rechtliche Seiten und automatisch versendete E-Mails verwalten.
            </p>
        </header>
        <ConfigurationNav />
        <form
            class="space-y-6"
            @submit.prevent="form.patch('/konfiguration/startseite')"
        >
            <section class="rounded-xl border bg-card">
                <div class="border-b px-5 py-4">
                    <h2 class="font-semibold">Rechtliche Seiten</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Die Texte erscheinen über die Links in der öffentlichen
                        Fußleiste. Zeilen, deren Platzhalter leer sind, werden
                        ausgeblendet; die Angaben dazu stehen in den
                        Vereinsdaten unter „Rechtliches“.
                    </p>
                    <p class="mt-2 text-sm text-muted-foreground">
                        {{
                            $address(
                                'Die Standardtexte sind ein unverbindlicher Ausgangspunkt und keine Rechtsberatung. Bitte prüfe sie für deinen Verein, insbesondere eingesetzte Dienstleister, die Speicherdauer der Server-Protokolle und freiwillige Angaben. Die Verantwortung für die Texte liegt beim Verein.',
                                'Die Standardtexte sind ein unverbindlicher Ausgangspunkt und keine Rechtsberatung. Bitte prüfen Sie sie für Ihren Verein, insbesondere eingesetzte Dienstleister, die Speicherdauer der Server-Protokolle und freiwillige Angaben. Die Verantwortung für die Texte liegt beim Verein.',
                            )
                        }}
                    </p>
                </div>
                <div class="space-y-6 p-5">
                    <div
                        v-for="item in legal"
                        :key="item.key"
                        class="space-y-2"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <Label :for="item.key">{{ item.label }}</Label>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="form[item.key] = defaults[item.key]"
                                >Auf Standard zurücksetzen</Button
                            >
                        </div>
                        <Textarea
                            :id="item.key"
                            v-model="form[item.key]"
                            :rows="item.rows"
                            required
                        />
                    </div>
                </div>
            </section>

            <section class="rounded-xl border bg-card">
                <div class="border-b px-5 py-4">
                    <h2 class="font-semibold">E-Mail-Texte</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Logo und Vereinsname ergänzt das System automatisch.
                        Zugangs- und Bestätigungsmails erhalten zusätzlich eine
                        Schaltfläche, Gültigkeit und Zugangscode. Der
                        Willkommensmail wird der eingereichte Mitgliedsantrag
                        automatisch als PDF beigefügt.
                    </p>
                </div>
                <div class="space-y-7 p-5">
                    <div
                        v-for="mail in mails"
                        :key="mail.text"
                        class="space-y-3"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="font-medium">{{ mail.label }}</h3>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="
                                    form[mail.subject] = defaults[mail.subject];
                                    form[mail.text] = defaults[mail.text];
                                "
                                >Auf Standard zurücksetzen</Button
                            >
                        </div>
                        <div class="space-y-2">
                            <Label :for="mail.subject">Betreff</Label>
                            <input
                                :id="mail.subject"
                                v-model="form[mail.subject]"
                                required
                                maxlength="255"
                                class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label :for="mail.text">Nachricht</Label>
                            <Textarea
                                :id="mail.text"
                                v-model="form[mail.text]"
                                rows="7"
                                required
                            />
                        </div>
                    </div>
                </div>
            </section>

            <details class="rounded-xl border bg-card p-5 text-sm">
                <summary class="font-medium">Verfügbare Platzhalter</summary>
                <p class="mt-3 text-muted-foreground">
                    Dieselben Platzhalter gelten für rechtliche Seiten und
                    E-Mails. HTML wird nicht ausgeführt.
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <code
                        v-for="placeholder in placeholders"
                        :key="placeholder"
                        class="rounded bg-muted p-1 text-xs"
                        >{{ placeholder }}</code
                    >
                </div>
            </details>

            <p
                v-for="error in form.errors"
                :key="error"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ error }}
            </p>
            <Button :disabled="form.processing">Konfiguration speichern</Button>
        </form>
    </div>
</template>
