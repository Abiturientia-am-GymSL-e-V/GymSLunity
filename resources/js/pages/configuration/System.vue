<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    ArchiveRestore,
    CheckCircle2,
    CircleAlert,
    Database,
    Download,
    ServerCog,
    Upload,
} from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import ConfigurationNav from '@/components/configuration/ConfigurationNav.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    download as downloadConfiguration,
    restore as restoreConfiguration,
} from '@/routes/configuration/backup/configuration';
import {
    download as downloadDatabase,
    restore as restoreDatabase,
} from '@/routes/configuration/backup/database';

defineProps<{
    runtime: {
        productName: string;
        environment: string;
        debug: boolean;
        url: string;
        displayTimezone: string;
        locale: string;
        database: string;
        cache: string;
        session: string;
        sessionEncrypted: boolean;
        secureCookie: boolean;
        queue: string;
        mail: string;
    };
    redis: {
        inUse: boolean;
        client: string;
        available: boolean;
        hostConfigured: boolean;
    };
    backupError: string | null;
    checks: Array<{ label: string; ok: boolean; detail: string }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Konfiguration', href: '/konfiguration/verein' },
            { title: 'System' },
        ],
    },
});

const labels: Record<string, string> = {
    productName: 'Produktname',
    environment: 'Umgebung',
    debug: 'Debug-Modus',
    url: 'Öffentliche URL',
    displayTimezone: 'Anzeige-Zeitzone',
    locale: 'Sprache',
    database: 'Datenbanktreiber',
    cache: 'Cache-Treiber',
    session: 'Sitzungs-Treiber',
    sessionEncrypted: 'Sitzungsverschlüsselung',
    secureCookie: 'Nur-HTTPS-Cookie',
    queue: 'Warteschlangen-Treiber',
    mail: 'E-Mail-Treiber',
};
const display = (value: string | boolean) =>
    typeof value === 'boolean' ? (value ? 'Aktiv' : 'Inaktiv') : value;

const configurationRestoreForm = useForm<{
    configuration_backup: File | null;
    confirmation: string;
}>({ configuration_backup: null, confirmation: '' });
const databaseRestoreForm = useForm<{
    database_backup: File | null;
    confirmation: string;
}>({ database_backup: null, confirmation: '' });

function chooseConfigurationBackup(event: Event) {
    configurationRestoreForm.configuration_backup =
        (event.target as HTMLInputElement).files?.[0] ?? null;
}

function chooseDatabaseBackup(event: Event) {
    databaseRestoreForm.database_backup =
        (event.target as HTMLInputElement).files?.[0] ?? null;
}

function importConfiguration() {
    configurationRestoreForm.post(restoreConfiguration.url(), {
        forceFormData: true,
        onSuccess: () => configurationRestoreForm.reset(),
    });
}

function importDatabase() {
    databaseRestoreForm.post(restoreDatabase.url(), {
        forceFormData: true,
        onSuccess: () => databaseRestoreForm.reset(),
    });
}
</script>

<template>
    <Head title="Systemkonfiguration" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Konfiguration</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Wirksame Servereinstellungen und Hinweise für den sicheren
                Betrieb prüfen.
            </p>
        </header>
        <ConfigurationNav />

        <Alert variant="info">
            <ServerCog class="size-4" />
            <AlertTitle>Serverkonfiguration ist schreibgeschützt</AlertTitle>
            <AlertDescription>
                Zugangsdaten und Infrastrukturparameter werden bewusst nicht
                über die Weboberfläche geändert.
                {{ $address('Passe', 'Passen Sie') }} die Datei
                <code class="rounded bg-muted px-1 py-0.5">.env</code> auf dem
                Server an und führe danach
                <code class="rounded bg-muted px-1 py-0.5"
                    >php artisan optimize</code
                >
                aus. Der Produktname GymSLunity ist fest; den Vereinsnamen
                {{
                    $address(
                        'pflegst du unter „Vereinsdaten“.',
                        'pflegen Sie unter „Vereinsdaten“.',
                    )
                }}
            </AlertDescription>
        </Alert>

        <StatusAlert
            v-if="backupError"
            type="error"
            title="Backup konnte nicht erstellt werden"
        >
            {{ backupError }}
        </StatusAlert>

        <section
            class="rounded-xl border bg-card"
            aria-labelledby="backup-title"
        >
            <div class="border-b px-5 py-4">
                <h2
                    id="backup-title"
                    class="flex items-center gap-2 text-sm font-semibold"
                >
                    <ArchiveRestore class="size-4" />Backup &amp;
                    Wiederherstellung
                </h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    Sicherungen herunterladen oder einen früheren Stand
                    kontrolliert wiederherstellen.
                </p>
            </div>

            <div class="grid divide-y lg:grid-cols-2 lg:divide-x lg:divide-y-0">
                <div class="space-y-5 p-5">
                    <div>
                        <h3 class="text-sm font-semibold">
                            Einstellungen &amp; Konfiguration
                        </h3>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Enthält Vereinsdaten, Mitgliedsfelder,
                            E-Mail-Einstellungen und Vereinslogo. Benutzer- und
                            Mitgliedsdaten sind nicht enthalten. Das
                            SMTP-Passwort bleibt mit dem aktuellen
                            Anwendungsschlüssel verschlüsselt.
                        </p>
                    </div>
                    <Button as-child variant="outline">
                        <a :href="downloadConfiguration.url()" download>
                            <Download class="size-4" />Konfiguration
                            herunterladen
                        </a>
                    </Button>

                    <form
                        class="space-y-3"
                        @submit.prevent="importConfiguration"
                    >
                        <div class="space-y-2">
                            <Label for="configuration-backup"
                                >Konfigurationssicherung</Label
                            >
                            <Input
                                id="configuration-backup"
                                type="file"
                                accept=".json,application/json"
                                :disabled="configurationRestoreForm.processing"
                                :aria-invalid="
                                    !!configurationRestoreForm.errors
                                        .configuration_backup
                                "
                                @change="chooseConfigurationBackup"
                            />
                            <InputError
                                :message="
                                    configurationRestoreForm.errors
                                        .configuration_backup
                                "
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="configuration-confirmation">
                                Zur Bestätigung WIEDERHERSTELLEN eingeben
                            </Label>
                            <Input
                                id="configuration-confirmation"
                                v-model="configurationRestoreForm.confirmation"
                                autocomplete="off"
                                :disabled="configurationRestoreForm.processing"
                                :aria-invalid="
                                    !!configurationRestoreForm.errors
                                        .confirmation
                                "
                            />
                            <InputError
                                :message="
                                    configurationRestoreForm.errors.confirmation
                                "
                            />
                        </div>
                        <Button
                            type="submit"
                            variant="outline"
                            :disabled="
                                !configurationRestoreForm.configuration_backup ||
                                configurationRestoreForm.confirmation !==
                                    'WIEDERHERSTELLEN' ||
                                configurationRestoreForm.processing
                            "
                        >
                            <Spinner
                                v-if="configurationRestoreForm.processing"
                            />
                            <Upload v-else class="size-4" />Konfiguration
                            importieren
                        </Button>
                    </form>
                </div>

                <div class="space-y-5 p-5">
                    <div>
                        <h3 class="text-sm font-semibold">
                            Vollständige Datenbank
                        </h3>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Enthält sämtliche Benutzer-, Mitglieder-, Zahlungs-
                            und Verwaltungsdaten. Dateien aus dem Speicher und
                            die Serverdatei <code>.env</code> sind nicht
                            enthalten.
                        </p>
                    </div>
                    <Button as-child variant="outline">
                        <a :href="downloadDatabase.url()" download>
                            <Download class="size-4" />Datenbank herunterladen
                        </a>
                    </Button>

                    <Alert variant="warning">
                        <CircleAlert class="size-4" />
                        <AlertTitle>Bestehende Daten werden ersetzt</AlertTitle>
                        <AlertDescription>
                            Der Import schaltet die Anwendung vorübergehend in
                            den Wartungsmodus. Direkt davor wird automatisch
                            eine lokale Sicherheitssicherung angelegt.
                        </AlertDescription>
                    </Alert>

                    <form class="space-y-3" @submit.prevent="importDatabase">
                        <div class="space-y-2">
                            <Label for="database-backup"
                                >Datenbanksicherung</Label
                            >
                            <Input
                                id="database-backup"
                                type="file"
                                accept=".zip,application/zip"
                                :disabled="databaseRestoreForm.processing"
                                :aria-invalid="
                                    !!databaseRestoreForm.errors.database_backup
                                "
                                @change="chooseDatabaseBackup"
                            />
                            <InputError
                                :message="
                                    databaseRestoreForm.errors.database_backup
                                "
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="database-confirmation">
                                Zur Bestätigung WIEDERHERSTELLEN eingeben
                            </Label>
                            <Input
                                id="database-confirmation"
                                v-model="databaseRestoreForm.confirmation"
                                autocomplete="off"
                                :disabled="databaseRestoreForm.processing"
                                :aria-invalid="
                                    !!databaseRestoreForm.errors.confirmation
                                "
                            />
                            <InputError
                                :message="
                                    databaseRestoreForm.errors.confirmation
                                "
                            />
                        </div>
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="
                                !databaseRestoreForm.database_backup ||
                                databaseRestoreForm.confirmation !==
                                    'WIEDERHERSTELLEN' ||
                                databaseRestoreForm.processing
                            "
                        >
                            <Spinner v-if="databaseRestoreForm.processing" />
                            <Upload v-else class="size-4" />Datenbank
                            wiederherstellen
                        </Button>
                    </form>
                </div>
            </div>
        </section>

        <section class="rounded-xl border bg-card">
            <h2 class="border-b px-5 py-4 text-sm font-semibold">
                Aktive Laufzeitkonfiguration
            </h2>
            <dl class="grid gap-x-6 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="(value, key) in runtime"
                    :key="key"
                    class="border-b px-5 py-4 last:border-b-0 sm:[&:nth-last-child(-n+2)]:border-b-0 lg:[&:nth-last-child(-n+3)]:border-b-0"
                >
                    <dt class="text-xs font-medium text-muted-foreground">
                        {{ labels[key] ?? key }}
                    </dt>
                    <dd class="mt-1 text-sm font-medium break-words">
                        {{ display(value) }}
                    </dd>
                </div>
            </dl>
        </section>

        <section class="rounded-xl border bg-card">
            <h2
                class="flex items-center gap-2 border-b px-5 py-4 text-sm font-semibold"
            >
                <Database class="size-4" />Redis
            </h2>
            <div class="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <p class="text-xs text-muted-foreground">Verwendung</p>
                    <p class="mt-1 text-sm font-medium">
                        {{
                            redis.inUse
                                ? 'Aktiv verwendet'
                                : 'Optional / nicht verwendet'
                        }}
                    </p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">Client</p>
                    <p class="mt-1 text-sm font-medium">{{ redis.client }}</p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">
                        PHP-Unterstützung
                    </p>
                    <p class="mt-1 text-sm font-medium">
                        {{ redis.available ? 'Verfügbar' : 'Nicht verfügbar' }}
                    </p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">Verbindungsziel</p>
                    <p class="mt-1 text-sm font-medium">
                        {{
                            redis.hostConfigured
                                ? 'Konfiguriert'
                                : 'Nicht konfiguriert'
                        }}
                    </p>
                </div>
            </div>
        </section>

        <section class="rounded-xl border bg-card">
            <h2 class="border-b px-5 py-4 text-sm font-semibold">
                Prüfung für den Produktivbetrieb
            </h2>
            <div class="divide-y">
                <div
                    v-for="check in checks"
                    :key="check.label"
                    class="flex gap-3 px-5 py-4"
                >
                    <CheckCircle2
                        v-if="check.ok"
                        class="mt-0.5 size-5 shrink-0 text-emerald-600"
                    />
                    <CircleAlert
                        v-else
                        class="mt-0.5 size-5 shrink-0 text-amber-600"
                    />
                    <div>
                        <p class="text-sm font-medium">{{ check.label }}</p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ check.detail }}
                        </p>
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>
