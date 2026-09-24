<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import ConfigurationNav from '@/components/configuration/ConfigurationNav.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
const props = defineProps<{
    settings: {
        selfservice_enabled: boolean;
        public_join_enabled: boolean;
        membership_activation: 'immediate' | 'approval';
        application_text: string;
        sepa_text: string;
        guardian_text: string;
        receipt_notes: string;
        receipt_donation_notes: string;
    };
    version: number;
    defaults: Record<string, string>;
    placeholders: string[];
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Konfiguration', href: '/konfiguration/verein' },
            { title: 'Selfservice & Formulare' },
        ],
    },
});
const form = useForm({ ...props.settings, version: props.version });
const templates = [
    { key: 'application_text' as const, label: 'Mitgliedsantrag' },
    { key: 'sepa_text' as const, label: 'SEPA-Mandat' },
    { key: 'guardian_text' as const, label: 'Zustimmung Sorgeberechtigte' },
    { key: 'receipt_notes' as const, label: 'Allgemeine Quittungshinweise' },
    {
        key: 'receipt_donation_notes' as const,
        label: 'Vereinfachter Spendennachweis für gemeinnützige Vereine',
    },
];
</script>
<template>
    <Head title="Selfservice & Formulare" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Konfiguration</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Selfservice, Beitritt und Formulartexte verwalten.
            </p>
        </header>
        <ConfigurationNav />
        <form
            class="space-y-6"
            @submit.prevent="form.patch('/konfiguration/selfservice')"
        >
            <section class="rounded-xl border bg-card">
                <div class="border-b px-5 py-4">
                    <h2 class="font-semibold">Zugang und Aktivierung</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Lege fest, wer den Selfservice nutzen kann und wann ein
                        Beitritt wirksam wird.
                    </p>
                </div>
                <div class="space-y-5 p-5">
                    <label class="flex items-center gap-3 text-sm"
                        ><input
                            v-model="form.selfservice_enabled"
                            type="checkbox"
                        />
                        Mitglieder-Selfservice aktivieren</label
                    >
                    <label class="flex items-center gap-3 text-sm"
                        ><input
                            v-model="form.public_join_enabled"
                            type="checkbox"
                        />
                        Öffentlichen Online-Beitritt anbieten (bei aktiviertem
                        Selfservice)</label
                    >
                    <div class="space-y-2">
                        <Label for="membership-activation"
                            >Aktivierung der Mitgliedschaft</Label
                        >
                        <select
                            id="membership-activation"
                            v-model="form.membership_activation"
                            class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="immediate">Sofort aktivieren</option>
                            <option value="approval">Erst nach Freigabe</option>
                        </select>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Bei „Erst nach Freigabe“ bleibt die Person bis zur
                        Freigabe unter Mitglieder → Beitrittsanträge ein
                        Kontakt. Das Eintrittsdatum ist der Tag der Freigabe.
                        Die Auswahl gilt für neue Anträge von Kontakten und
                        neuen Interessenten; bereits offene Anträge bleiben
                        freigabepflichtig.
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Mitglieder und Kontakte melden sich über einen
                        einmaligen E-Mail-Link an. Der öffentliche Beitritt
                        bestätigt zuerst die E-Mail-Adresse. Für Personen ohne
                        hinterlegte E-Mail-Adresse pflegt die Verwaltung
                        zunächst eine Adresse ein.
                    </p>
                </div>
            </section>
            <section class="rounded-xl border bg-card">
                <div class="border-b px-5 py-4">
                    <h2 class="font-semibold">Formulartexte</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Die Texte werden vor der Unterschrift angezeigt und
                        unverändert im jeweiligen PDF gespeichert. Die
                        allgemeinen Quittungshinweise erscheinen auf jeder
                        Quittung. Ist der Verein als gemeinnützig markiert, wird
                        zusätzlich der vereinfachte Spendennachweis angehängt;
                        seine steuerlichen Angaben stammen aus der
                        Spendenkonfiguration.
                    </p>
                </div>
                <div class="space-y-5 p-5">
                    <div
                        v-for="item in templates"
                        :key="item.key"
                        class="space-y-2"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <Label :for="item.key">{{ item.label }}</Label
                            ><Button
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
                            rows="7"
                            required
                        />
                    </div>
                    <details class="rounded-md border p-4 text-sm">
                        <summary>
                            Verfügbare Platzhalter der Vereinsstammdaten
                        </summary>
                        <p class="mt-3 text-sm">
                            Die Platzhalter werden bei der Anzeige und beim
                            Erstellen des Dokuments ersetzt. Texte sind
                            Klartext; HTML wird nicht ausgeführt.
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
                </div>
            </section>
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
