<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import ConfigurationNav from '@/components/configuration/ConfigurationNav.vue';
import InputError from '@/components/InputError.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';

const props = defineProps<{
    version: number;
    smallBusinessRegulationEnabled: boolean;
    financeMandateText: string;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Konfiguration', href: '/konfiguration/verein' },
            { title: 'Buchhaltung' },
        ],
    },
});
const form = useForm({
    version: props.version,
    small_business_regulation_enabled: props.smallBusinessRegulationEnabled,
    finance_mandate_text: props.financeMandateText,
});
function save() {
    form.patch('/konfiguration/buchhaltung', {
        preserveScroll: true,
        onSuccess: () => {
            form.version = props.version;
            form.defaults();
        },
    });
}
</script>

<template>
    <Head title="Buchhaltung konfigurieren" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Konfiguration</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Vorgaben für Buchhaltung und Rechnungserstellung festlegen.
            </p>
        </header>
        <ConfigurationNav />

        <form class="space-y-5" novalidate @submit.prevent="save">
            <InputError :message="form.errors.version" />
            <section class="rounded-xl border bg-card">
                <div class="border-b px-5 py-4">
                    <h2 class="font-semibold">Rechnungswesen</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Steuerliche Grundeinstellungen für neu erstellte
                        Rechnungen.
                    </p>
                </div>
                <div class="space-y-5 p-5">
                    <label class="flex items-start gap-3 rounded-lg border p-4">
                        <Checkbox
                            :model-value="
                                form.small_business_regulation_enabled
                            "
                            class="mt-0.5"
                            @update:model-value="
                                form.small_business_regulation_enabled =
                                    Boolean($event)
                            "
                        />
                        <span class="min-w-0">
                            <span class="block text-sm font-medium">
                                Kleinunternehmerregelung nach § 19 UStG anwenden
                            </span>
                            <span
                                class="mt-1 block text-sm text-muted-foreground"
                            >
                                Neue Rechnungspositionen werden automatisch mit
                                0 % Umsatzsteuer erstellt. PDF und XRechnung
                                erhalten den notwendigen Hinweis auf die
                                Steuerbefreiung. Bereits ausgestellte Rechnungen
                                bleiben unverändert.
                            </span>
                        </span>
                    </label>
                    <StatusAlert type="warning" title="Steuerliche Prüfung">
                        Aktiviere diese Einstellung nur, wenn der Verein die
                        Voraussetzungen des § 19 UStG erfüllt. Die Anwendung
                        nimmt keine steuerliche Einzelfallprüfung vor.
                    </StatusAlert>
                    <InputError
                        :message="form.errors.small_business_regulation_enabled"
                    />
                </div>
            </section>

            <section class="rounded-xl border bg-card">
                <div class="border-b px-5 py-4">
                    <h2 class="font-semibold">SEPA-Mandate</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Standardtext für neu angelegte Mandate im
                        Formularbereich.
                    </p>
                </div>
                <div class="space-y-3 p-5">
                    <label class="block space-y-2">
                        <span class="block text-sm font-medium"
                            >Mandatstext *</span
                        >
                        <Textarea
                            v-model="form.finance_mandate_text"
                            rows="7"
                            maxlength="5000"
                            required
                        />
                    </label>
                    <p class="text-xs text-muted-foreground">
                        Verfügbare Platzhalter:
                        <code v-pre>{{ verein.name }}</code> und
                        <code v-pre>{{ verein.glaeubiger_id }}</code
                        >. Änderungen gelten nur für neue Mandate.
                    </p>
                    <StatusAlert type="warning" title="Rechtliche Prüfung">
                        Die Vorlage orientiert sich am
                        SEPA-Basislastschriftmandat. Individuelle Ergänzungen
                        sollten rechtlich geprüft werden.
                    </StatusAlert>
                    <InputError :message="form.errors.finance_mandate_text" />
                </div>
            </section>

            <div class="flex justify-end">
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    <Save v-else class="size-4" aria-hidden="true" />
                    Speichern
                </Button>
            </div>
        </form>
    </div>
</template>
