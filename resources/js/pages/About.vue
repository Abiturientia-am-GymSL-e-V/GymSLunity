<script setup lang="ts">
import {
    Boxes,
    CalendarDays,
    ChartNoAxesCombined,
    CircleAlert,
    FileText,
    HandCoins,
    HeartHandshake,
    Info,
    MessageSquareText,
    Settings2,
    ShieldAlert,
    UsersRound,
} from '@lucide/vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

type Tab = 'software' | 'project' | 'disclaimer';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Über GymSLunity' }],
    },
});

const activeTab = ref<Tab>('software');
const tabs = [
    ['software', 'Über die Software', Info],
    ['project', 'Über das Projekt', UsersRound],
    ['disclaimer', 'Disclaimer', ShieldAlert],
] as const;

const featureAreas = [
    {
        title: 'Mitgliederverwaltung',
        icon: UsersRound,
        description:
            'Mitglieds- und Kontaktdaten zentral pflegen, eigene Datenfelder verwenden sowie Bestände importieren, filtern und exportieren.',
    },
    {
        title: 'Beiträge und Finanzen',
        icon: HandCoins,
        description:
            'Beitragskonten, Forderungen, Zahlungen und SEPA-Abläufe nachvollziehbar verwalten und Bankdaten kontrolliert verarbeiten.',
    },
    {
        title: 'Spenden',
        icon: HeartHandshake,
        description:
            'Geld- und Sachzuwendungen erfassen, dokumentieren und Zuwendungsbestätigungen aus den hinterlegten Vereinsdaten erstellen.',
    },
    {
        title: 'Formulare und Dokumente',
        icon: FileText,
        description:
            'Quittungen erstellen, digital unterzeichnen, als PDF ausgeben, wiederfinden und per E-Mail versenden.',
    },
    {
        title: 'Kommunikation',
        icon: MessageSquareText,
        description:
            'Personalisierte Serien-E-Mails und Serienbriefe mit Filtern, Platzhaltern, Anhängen und Versandprotokoll vorbereiten.',
    },
    {
        title: 'Auswertungen',
        icon: ChartNoAxesCombined,
        description:
            'Mitgliederentwicklung, Vereinsstruktur, Beitrags- und Spendenzahlen sowie die Qualität des Datenbestands auswerten.',
    },
    {
        title: 'Inventar',
        icon: Boxes,
        description:
            'Vereinseigentum mit Inventarnummern, Wertentwicklung, Standorten und Statusänderungen dokumentieren.',
    },
    {
        title: 'Kalender',
        icon: CalendarDays,
        description:
            'Vereinstermine und Geburtstage in mehreren farbigen Kalendern planen und per öffentlichem oder persönlichem iCal-Abo zielgerichtet teilen.',
    },
    {
        title: 'Selfservice',
        icon: ShieldAlert,
        description:
            'Mitgliedern einen geschützten Zugang zu ihren Daten, Dokumenten und freigegebenen Kalendern sowie einen digitalen Beitrittsprozess anbieten.',
    },
    {
        title: 'Konfiguration',
        icon: Settings2,
        description:
            'Vereinsstammdaten, Benutzerrechte, eingesetzte Softwaremodule, E-Mail-Versand, Formulartexte und fachliche Einstellungen zentral verwalten.',
    },
] as const;
</script>

<template>
    <Head title="Über GymSLunity" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">
                Über GymSLunity
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Informationen zur Anwendung, ihrer Entstehung und zu den
                Verantwortlichkeiten beim Einsatz.
            </p>
        </header>

        <section
            aria-label="GymSLunity-Projekt"
            class="flex flex-col items-center gap-5 rounded-xl border bg-card p-6 text-center"
        >
            <img
                src="/images/gymslunity-logo.png"
                alt="GymSLunity"
                class="h-auto max-h-48 max-w-full object-contain"
            />
            <div class="flex flex-wrap justify-center gap-2">
                <a
                    href="https://github.com/Abiturientia-am-GymSL-e-V/GymSLunity/releases"
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label="GymSLunity-Releases auf GitHub ansehen"
                >
                    <img
                        alt="Aktuelle GymSLunity-Version"
                        src="https://img.shields.io/github/v/release/Abiturientia-am-GymSL-e-V/GymSLunity"
                        class="max-w-full"
                    />
                </a>
                <a
                    href="https://github.com/Abiturientia-am-GymSL-e-V/GymSLunity/blob/main/LICENSE"
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label="Lizenz EUPL-1.2 auf GitHub ansehen"
                >
                    <img
                        alt="Lizenz: EUPL-1.2"
                        src="https://img.shields.io/badge/License-EUPL--1.2-yellow.svg"
                        class="max-w-full"
                    />
                </a>
            </div>
        </section>

        <nav
            role="tablist"
            aria-label="Informationen über GymSLunity"
            class="flex flex-wrap gap-2 border-b pb-4"
        >
            <button
                v-for="tab in tabs"
                :id="`about-tab-${tab[0]}`"
                :key="tab[0]"
                type="button"
                role="tab"
                :aria-selected="activeTab === tab[0]"
                :aria-controls="`about-panel-${tab[0]}`"
                class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:bg-muted"
                :class="
                    activeTab === tab[0]
                        ? 'bg-muted text-foreground'
                        : 'text-muted-foreground'
                "
                @click="activeTab = tab[0]"
            >
                <component :is="tab[2]" class="size-4" />{{ tab[1] }}
            </button>
        </nav>

        <section
            v-if="activeTab === 'software'"
            id="about-panel-software"
            role="tabpanel"
            aria-labelledby="about-tab-software"
            class="space-y-6"
        >
            <div class="max-w-3xl space-y-3">
                <h2 class="text-xl font-semibold">
                    Vereinsarbeit an einem Ort
                </h2>
                <p class="text-sm leading-6 text-muted-foreground">
                    GymSLunity ist eine webbasierte Komplettlösung für die
                    tägliche Vereinsverwaltung. Die Anwendung verbindet
                    Mitgliederverwaltung, Finanzen, Kalender, Kommunikation und
                    Dokumente in einer gemeinsamen, rollenbasierten
                    Arbeitsumgebung. Wiederkehrende Abläufe werden gebündelt,
                    während Änderungen und fachliche Vorgänge nachvollziehbar
                    bleiben.
                </p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <article
                    v-for="area in featureAreas"
                    :key="area.title"
                    class="rounded-xl border bg-card p-5"
                >
                    <div class="flex items-center gap-3">
                        <span class="rounded-lg bg-muted p-2">
                            <component :is="area.icon" class="size-4" />
                        </span>
                        <h3 class="font-semibold">{{ area.title }}</h3>
                    </div>
                    <p class="mt-3 text-sm leading-6 text-muted-foreground">
                        {{ area.description }}
                    </p>
                </article>
            </div>
        </section>

        <section
            v-else-if="activeTab === 'project'"
            id="about-panel-project"
            role="tabpanel"
            aria-labelledby="about-tab-project"
            class="grid gap-5 lg:grid-cols-2"
        >
            <article class="rounded-xl border bg-card p-5">
                <h2 class="text-lg font-semibold">Aus der Praxis entstanden</h2>
                <div
                    class="mt-3 space-y-3 text-sm leading-6 text-muted-foreground"
                >
                    <p>
                        GymSLunity ist ein von Schülerinnen und Schülern
                        getragenes Projekt, das aus den praktischen
                        Anforderungen eines Schulvereins entstanden ist. Damit
                        verbindet es Softwareentwicklung mit echter
                        ehrenamtlicher Vereinsarbeit.
                    </p>
                    <p>
                        Entscheidungen über Funktionen und Arbeitsabläufe
                        orientieren sich an Aufgaben, die im Vereinsalltag
                        tatsächlich anfallen – von der Mitgliederpflege bis zur
                        Kommunikation und Dokumentation.
                    </p>
                </div>
            </article>
            <article class="rounded-xl border bg-card p-5">
                <h2 class="text-lg font-semibold">Offen und selbstbestimmt</h2>
                <div
                    class="mt-3 space-y-3 text-sm leading-6 text-muted-foreground"
                >
                    <p>
                        Ziel ist eine offene Komplettlösung, die Vereine selbst
                        betreiben, verstehen und weiterentwickeln können. Gerade
                        in einer Zeit wachsender Abhängigkeit von
                        kostenpflichtigen SaaS-Angeboten soll GymSLunity eine
                        transparente Alternative schaffen.
                    </p>
                    <p>
                        Der offene Entwicklungsansatz lädt dazu ein, Erfahrungen
                        zu teilen, Fehler gemeinsam zu beheben und Funktionen
                        für unterschiedliche Vereinsformen weiterzudenken.
                    </p>
                </div>
                <a
                    href="https://github.com/Abiturientia-am-GymSL-e-V/GymSLunity"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="mt-4 inline-flex items-center text-sm font-medium text-primary hover:underline"
                    >Projekt auf GitHub ansehen →</a
                >
            </article>
        </section>

        <section
            v-else
            id="about-panel-disclaimer"
            role="tabpanel"
            aria-labelledby="about-tab-disclaimer"
            class="space-y-5"
        >
            <Alert variant="warning">
                <CircleAlert />
                <AlertTitle>Hinweis zur Verantwortung</AlertTitle>
                <AlertDescription>
                    GymSLunity unterstützt bei der Vereinsarbeit, ersetzt jedoch
                    keine rechtliche, steuerliche oder datenschutzrechtliche
                    Beratung. Die Verantwortung für den ordnungsgemäßen Einsatz
                    verbleibt beim betreibenden Verein beziehungsweise bei den
                    jeweils handelnden Personen.
                </AlertDescription>
            </Alert>

            <div class="grid gap-5 md:grid-cols-2">
                <article class="rounded-xl border bg-card p-5">
                    <h3 class="font-semibold">Rechtliche Aktualität</h3>
                    <p class="mt-2 text-sm leading-6 text-muted-foreground">
                        Vorlagen, Textbausteine und erzeugte Dokumente – etwa
                        Quittungen oder Zuwendungsbestätigungen – können trotz
                        sorgfältiger Erstellung unvollständig, veraltet oder für
                        den konkreten Einzelfall ungeeignet sein. Nutzer müssen
                        Inhalte, Pflichtangaben und Berechnungen vor der
                        Verwendung anhand der aktuell geltenden Vorgaben prüfen.
                    </p>
                </article>
                <article class="rounded-xl border bg-card p-5">
                    <h3 class="font-semibold">Datenschutz</h3>
                    <p class="mt-2 text-sm leading-6 text-muted-foreground">
                        Der Betreiber entscheidet, welche personenbezogenen
                        Daten verarbeitet werden, und ist für Rechtsgrundlagen,
                        Informationspflichten, Berechtigungen, Löschfristen und
                        Betroffenenrechte verantwortlich. Die Software allein
                        stellt keine Datenschutzkonformität her.
                    </p>
                </article>
                <article class="rounded-xl border bg-card p-5">
                    <h3 class="font-semibold">Betrieb und Datensicherheit</h3>
                    <p class="mt-2 text-sm leading-6 text-muted-foreground">
                        Installation, Zugriffsschutz, Updates, sichere
                        Serverkonfiguration, Verschlüsselung, Backups und deren
                        Wiederherstellung liegen in der Verantwortung des
                        Betreibers. Vor dem produktiven Einsatz sollte die
                        Konfiguration fachkundig geprüft werden.
                    </p>
                </article>
                <article class="rounded-xl border bg-card p-5">
                    <h3 class="font-semibold">Gewährleistung und Prüfung</h3>
                    <p class="mt-2 text-sm leading-6 text-muted-foreground">
                        Es wird keine Gewähr für Fehlerfreiheit, Verfügbarkeit
                        oder Eignung für einen bestimmten Zweck übernommen.
                        Eingaben, Ausgaben und automatisierte Verarbeitung sind
                        vor verbindlichen Handlungen durch die verantwortlichen
                        Personen zu kontrollieren.
                    </p>
                </article>
            </div>
        </section>
    </div>
</template>
