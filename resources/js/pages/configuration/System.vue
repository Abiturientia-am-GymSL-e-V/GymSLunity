<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CheckCircle2, CircleAlert, Database, ServerCog } from '@lucide/vue';
import ConfigurationNav from '@/components/configuration/ConfigurationNav.vue';

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

        <div
            class="flex gap-3 rounded-xl border border-blue-500/30 bg-blue-500/10 p-4"
        >
            <ServerCog
                class="mt-0.5 size-5 shrink-0 text-blue-700 dark:text-blue-300"
            />
            <div class="text-sm leading-6">
                <p class="font-semibold">
                    Serverkonfiguration ist schreibgeschützt
                </p>
                <p class="text-muted-foreground">
                    Zugangsdaten und Infrastrukturparameter werden bewusst nicht
                    über die Weboberfläche geändert. Passe die Datei
                    <code class="rounded bg-muted px-1 py-0.5">.env</code> auf
                    dem Server an und führe danach
                    <code class="rounded bg-muted px-1 py-0.5"
                        >php artisan optimize</code
                    >
                    aus. Der Produktname GymSLunity ist fest; den Vereinsnamen
                    pflegst du unter „Vereinsdaten“.
                </p>
            </div>
        </div>

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
