<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ChartBar,
    CircleAlert,
    CircleCheck,
    Download,
    FileDown,
    HandCoins,
    HeartHandshake,
    ListChecks,
    TrendingDown,
    TrendingUp,
    UserMinus,
    UserPlus,
    UsersRound,
} from '@lucide/vue';
import { computed, reactive } from 'vue';
import BarBreakdown from '@/components/statistics/BarBreakdown.vue';
import MemberTrendChart from '@/components/statistics/MemberTrendChart.vue';
import MoneyComparisonChart from '@/components/statistics/MoneyComparisonChart.vue';
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

type Tab = 'overview' | 'members' | 'finances' | 'quality';
type Breakdown = { label: string; count: number };
type TrendPoint = {
    key: string;
    label: string;
    active: number;
    joined: number;
    departed: number;
};
type StockRow = {
    birth_year: number | null;
    label: string;
    female: number;
    male: number;
    diverse: number;
    unspecified: number;
    total: number;
};

const props = defineProps<{
    activeTab: Tab;
    filters: { from: string; to: string; as_of: string };
    summary: {
        active_members: number;
        contacts: number;
        joined: number;
        departed: number;
        net_change: number;
    };
    memberTrend: TrendPoint[];
    memberBreakdowns: {
        membership_types: Breakdown[];
        age_groups: Breakdown[];
        genders: Breakdown[];
        payment_methods: Breakdown[];
        cities: Breakdown[];
    };
    stockReport: StockRow[];
    finances: {
        contributions: {
            count: number;
            assessed_cents: number;
            paid_cents: number;
            open_cents: number;
            overdue_count: number;
            overdue_cents: number;
            collection_rate: number;
        };
        donations: {
            count: number;
            amount_cents: number;
            average_cents: number;
            by_type: { label: string; count: number; amount_cents: number }[];
        };
        monthly: {
            key: string;
            label: string;
            contributions_cents: number;
            donations_cents: number;
        }[];
    };
    dataQuality: {
        score: number;
        checks: {
            key: string;
            label: string;
            description: string;
            count: number;
            percentage: number;
        }[];
    };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Auswertungen', href: '/auswertungen' }] },
});

const tabs = [
    ['overview', 'Übersicht', ChartBar, '/auswertungen'],
    ['members', 'Mitglieder', UsersRound, '/auswertungen/mitglieder'],
    ['finances', 'Finanzen', HandCoins, '/auswertungen/finanzen'],
    ['quality', 'Datenqualität', ListChecks, '/auswertungen/datenqualitaet'],
] as const;
const pageCopy: Record<Tab, { title: string; subtitle: string }> = {
    overview: {
        title: 'Auswertungen',
        subtitle: 'Vereinsentwicklung im gewählten Zeitraum auf einen Blick.',
    },
    members: {
        title: 'Mitgliederstruktur',
        subtitle:
            'Bestand, Altersgruppen und Zusammensetzung zum gewählten Stichtag.',
    },
    finances: {
        title: 'Finanzstatistik',
        subtitle: 'Beiträge und Spenden im gewählten Auswertungszeitraum.',
    },
    quality: {
        title: 'Datenqualität',
        subtitle: 'Vollständigkeit der Daten aktiver Mitglieder prüfen.',
    },
};
const filterForm = reactive({ ...props.filters });
const query = computed(() => new URLSearchParams(filterForm).toString());
const tabUrl = (path: string) => `${path}?${query.value}`;
const exportUrl = computed(
    () => `/auswertungen/bestandsmeldung.csv?${query.value}`,
);
const reportUrl = computed(() => `/auswertungen/bericht.pdf?${query.value}`);
const activePath = computed(
    () => tabs.find(([key]) => key === props.activeTab)?.[3] ?? '/auswertungen',
);
const applyFilters = () =>
    router.get(activePath.value, filterForm, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
const today = new Intl.DateTimeFormat('sv-SE').format(new Date());
const number = (value: number) => value.toLocaleString('de-DE');
const money = (cents: number) =>
    new Intl.NumberFormat('de-DE', {
        style: 'currency',
        currency: 'EUR',
    }).format(cents / 100);
const date = (value: string) =>
    new Intl.DateTimeFormat('de-DE').format(new Date(`${value}T00:00:00`));
const periodLabel = computed(
    () => `${date(props.filters.from)} bis ${date(props.filters.to)}`,
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
            aria-label="Auswertungsbereiche"
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
            >
                <component :is="tab[2]" class="size-4" />{{ tab[1] }}
            </Link>
        </nav>

        <form
            class="grid gap-3 rounded-xl border bg-card p-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-[repeat(3,minmax(0,1fr))_auto] 2xl:items-end"
            @submit.prevent="applyFilters"
        >
            <div class="min-w-0 space-y-2">
                <Label for="statistics-from">Zeitraum von</Label>
                <Input
                    id="statistics-from"
                    v-model="filterForm.from"
                    name="from"
                    type="date"
                    :max="filterForm.to"
                    required
                />
            </div>
            <div class="min-w-0 space-y-2">
                <Label for="statistics-to">Zeitraum bis</Label>
                <Input
                    id="statistics-to"
                    v-model="filterForm.to"
                    name="to"
                    type="date"
                    :min="filterForm.from"
                    :max="today"
                    required
                />
            </div>
            <div class="min-w-0 space-y-2 sm:col-span-2 xl:col-span-1">
                <Label for="statistics-as-of">Bestand zum</Label>
                <Input
                    id="statistics-as-of"
                    v-model="filterForm.as_of"
                    name="as_of"
                    type="date"
                    :max="today"
                    required
                />
            </div>
            <div
                class="flex flex-col gap-2 sm:col-span-2 sm:flex-row xl:col-span-3 2xl:col-span-1 2xl:flex-nowrap"
            >
                <Button type="submit" variant="outline">
                    Auswertung aktualisieren
                </Button>
                <Button as-child>
                    <a :href="reportUrl">
                        <FileDown class="size-4" aria-hidden="true" />
                        PDF-Bericht
                    </a>
                </Button>
            </div>
        </form>

        <template v-if="activeTab === 'overview'">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Card class="gap-3 py-5"
                    ><CardHeader class="px-5 pb-0"
                        ><div class="flex items-center justify-between gap-3">
                            <CardDescription>Aktive Mitglieder</CardDescription
                            ><UsersRound class="size-5 text-muted-foreground" />
                        </div>
                        <CardTitle class="text-3xl tabular-nums">{{
                            number(summary.active_members)
                        }}</CardTitle></CardHeader
                    ><CardContent class="px-5 text-xs text-muted-foreground"
                        >Zusätzlich {{ number(summary.contacts) }} Kontakte ·
                        Stichtag {{ date(filters.as_of) }}</CardContent
                    ></Card
                >
                <Card class="gap-3 py-5"
                    ><CardHeader class="px-5 pb-0"
                        ><div class="flex items-center justify-between gap-3">
                            <CardDescription>Eintritte</CardDescription
                            ><UserPlus class="size-5 text-emerald-600" />
                        </div>
                        <CardTitle class="text-3xl tabular-nums">{{
                            number(summary.joined)
                        }}</CardTitle></CardHeader
                    ><CardContent class="px-5 text-xs text-muted-foreground">{{
                        periodLabel
                    }}</CardContent></Card
                >
                <Card class="gap-3 py-5"
                    ><CardHeader class="px-5 pb-0"
                        ><div class="flex items-center justify-between gap-3">
                            <CardDescription>Abgänge</CardDescription
                            ><UserMinus class="size-5 text-rose-500" />
                        </div>
                        <CardTitle class="text-3xl tabular-nums">{{
                            number(summary.departed)
                        }}</CardTitle></CardHeader
                    ><CardContent class="px-5 text-xs text-muted-foreground"
                        >Austritte und verstorbene Mitglieder</CardContent
                    ></Card
                >
                <Card class="gap-3 py-5"
                    ><CardHeader class="px-5 pb-0"
                        ><div class="flex items-center justify-between gap-3">
                            <CardDescription>Nettoentwicklung</CardDescription
                            ><TrendingUp
                                v-if="summary.net_change >= 0"
                                class="size-5 text-emerald-600"
                            /><TrendingDown
                                v-else
                                class="size-5 text-rose-500"
                            />
                        </div>
                        <CardTitle
                            class="text-3xl tabular-nums"
                            :class="
                                summary.net_change < 0 ? 'text-rose-600' : ''
                            "
                            >{{ summary.net_change > 0 ? '+' : ''
                            }}{{ number(summary.net_change) }}</CardTitle
                        ></CardHeader
                    ><CardContent class="px-5 text-xs text-muted-foreground"
                        >Eintritte abzüglich Abgänge</CardContent
                    ></Card
                >
            </div>
            <Card class="gap-0 py-0"
                ><CardHeader class="border-b py-5"
                    ><CardTitle class="text-base"
                        >Mitgliederentwicklung</CardTitle
                    ><CardDescription
                        >Monatlicher Bestand sowie Eintritte und Abgänge ·
                        {{ periodLabel }}</CardDescription
                    ></CardHeader
                ><CardContent class="overflow-hidden py-5"
                    ><MemberTrendChart :points="memberTrend" /></CardContent
            ></Card>
            <div class="grid gap-4 lg:grid-cols-2">
                <BarBreakdown
                    title="Mitgliedsarten"
                    description="Aktive Mitglieder zum Stichtag"
                    :items="memberBreakdowns.membership_types"
                />
                <BarBreakdown
                    title="Altersgruppen"
                    description="Alter zum gewählten Stichtag"
                    :items="memberBreakdowns.age_groups"
                />
            </div>
        </template>

        <template v-else-if="activeTab === 'members'">
            <div class="grid gap-4 lg:grid-cols-2">
                <BarBreakdown
                    title="Mitgliedsarten"
                    description="Aktiver Bestand"
                    :items="memberBreakdowns.membership_types"
                />
                <BarBreakdown
                    title="Altersgruppen"
                    description="Alter zum Stichtag"
                    :items="memberBreakdowns.age_groups"
                />
                <BarBreakdown
                    title="Geschlecht"
                    description="Grundlage für Bestandsmeldungen"
                    :items="memberBreakdowns.genders"
                />
                <BarBreakdown
                    title="Zahlungsarten"
                    description="Hinterlegte Zahlungsart"
                    :items="memberBreakdowns.payment_methods"
                />
                <BarBreakdown
                    title="Wohnorte"
                    description="Die acht häufigsten Orte"
                    :items="memberBreakdowns.cities"
                />
            </div>
            <Card class="gap-0 overflow-hidden py-0">
                <CardHeader
                    class="border-b py-5 sm:flex-row sm:items-center sm:justify-between"
                    ><div>
                        <CardTitle class="text-base"
                            >Bestandsstruktur nach Geburtsjahr</CardTitle
                        ><CardDescription
                            >Aggregierte Grundlage für Verbandsmeldungen zum
                            Stichtag</CardDescription
                        >
                    </div>
                    <Button as-child variant="outline" size="sm"
                        ><a :href="exportUrl"
                            ><Download /> CSV exportieren</a
                        ></Button
                    ></CardHeader
                >
                <CardContent class="overflow-x-auto p-0">
                    <table class="w-full min-w-[680px] text-sm">
                        <thead
                            class="bg-muted/50 text-left text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">
                                    Geburtsjahr
                                </th>
                                <th class="px-3 py-3 text-right font-medium">
                                    Weiblich
                                </th>
                                <th class="px-3 py-3 text-right font-medium">
                                    Männlich
                                </th>
                                <th class="px-3 py-3 text-right font-medium">
                                    Divers
                                </th>
                                <th class="px-3 py-3 text-right font-medium">
                                    Ohne Angabe
                                </th>
                                <th class="px-5 py-3 text-right font-medium">
                                    Gesamt
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="row in stockReport" :key="row.label">
                                <td class="px-5 py-3 font-medium">
                                    {{ row.label }}
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums">
                                    {{ number(row.female) }}
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums">
                                    {{ number(row.male) }}
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums">
                                    {{ number(row.diverse) }}
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums">
                                    {{ number(row.unspecified) }}
                                </td>
                                <td
                                    class="px-5 py-3 text-right font-semibold tabular-nums"
                                >
                                    {{ number(row.total) }}
                                </td>
                            </tr>
                            <tr v-if="stockReport.length === 0">
                                <td
                                    colspan="6"
                                    class="px-5 py-8 text-center text-muted-foreground"
                                >
                                    Keine aktiven Mitglieder vorhanden.
                                </td>
                            </tr>
                        </tbody>
                        <tfoot
                            v-if="stockReport.length"
                            class="border-t bg-muted/30 font-semibold"
                        >
                            <tr>
                                <td class="px-5 py-3">Gesamt</td>
                                <td class="px-3 py-3 text-right tabular-nums">
                                    {{
                                        number(
                                            stockReport.reduce(
                                                (sum, row) => sum + row.female,
                                                0,
                                            ),
                                        )
                                    }}
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums">
                                    {{
                                        number(
                                            stockReport.reduce(
                                                (sum, row) => sum + row.male,
                                                0,
                                            ),
                                        )
                                    }}
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums">
                                    {{
                                        number(
                                            stockReport.reduce(
                                                (sum, row) => sum + row.diverse,
                                                0,
                                            ),
                                        )
                                    }}
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums">
                                    {{
                                        number(
                                            stockReport.reduce(
                                                (sum, row) =>
                                                    sum + row.unspecified,
                                                0,
                                            ),
                                        )
                                    }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    {{ number(summary.active_members) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </CardContent>
            </Card>
        </template>

        <template v-else-if="activeTab === 'finances'">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Card class="gap-2 py-5"
                    ><CardHeader class="px-5 pb-0"
                        ><CardDescription>Sollstellungen</CardDescription
                        ><CardTitle class="text-2xl tabular-nums">{{
                            money(finances.contributions.assessed_cents)
                        }}</CardTitle></CardHeader
                    ><CardContent class="px-5 text-xs text-muted-foreground"
                        >{{
                            number(finances.contributions.count)
                        }}
                        Forderungen</CardContent
                    ></Card
                >
                <Card class="gap-2 py-5"
                    ><CardHeader class="px-5 pb-0"
                        ><CardDescription>Bezahlt</CardDescription
                        ><CardTitle class="text-2xl tabular-nums">{{
                            money(finances.contributions.paid_cents)
                        }}</CardTitle></CardHeader
                    ><CardContent class="px-5 text-xs text-muted-foreground"
                        >Zahlungsquote
                        {{ finances.contributions.collection_rate }}
                        %</CardContent
                    ></Card
                >
                <Card class="gap-2 py-5"
                    ><CardHeader class="px-5 pb-0"
                        ><CardDescription>Offene Beiträge</CardDescription
                        ><CardTitle class="text-2xl tabular-nums">{{
                            money(finances.contributions.open_cents)
                        }}</CardTitle></CardHeader
                    ><CardContent class="px-5 text-xs text-muted-foreground"
                        >Davon
                        {{ money(finances.contributions.overdue_cents) }}
                        überfällig</CardContent
                    ></Card
                >
                <Card class="gap-2 py-5"
                    ><CardHeader class="px-5 pb-0"
                        ><CardDescription>Spenden</CardDescription
                        ><CardTitle class="text-2xl tabular-nums">{{
                            money(finances.donations.amount_cents)
                        }}</CardTitle></CardHeader
                    ><CardContent class="px-5 text-xs text-muted-foreground"
                        >{{ number(finances.donations.count) }} Spenden · Ø
                        {{
                            money(finances.donations.average_cents)
                        }}</CardContent
                    ></Card
                >
            </div>
            <Card class="gap-0 py-0"
                ><CardHeader class="border-b py-5"
                    ><CardTitle class="text-base"
                        >Beiträge und Spenden im Zeitverlauf</CardTitle
                    ><CardDescription
                        >Monatliche Sollstellung und Spendeneingänge ·
                        {{ periodLabel }}</CardDescription
                    ></CardHeader
                ><CardContent class="py-5"
                    ><MoneyComparisonChart
                        :points="finances.monthly" /></CardContent
            ></Card>
            <div class="grid gap-4 lg:grid-cols-2">
                <Card class="gap-0 py-0"
                    ><CardHeader class="border-b py-5"
                        ><CardTitle class="text-base">Beitragsstatus</CardTitle
                        ><CardDescription
                            >Alle Forderungen im
                            Auswertungszeitraum</CardDescription
                        ></CardHeader
                    ><CardContent class="space-y-5 py-5"
                        ><div>
                            <div class="mb-2 flex justify-between text-sm">
                                <span>Bezahlt</span
                                ><strong
                                    >{{
                                        finances.contributions.collection_rate
                                    }}
                                    %</strong
                                >
                            </div>
                            <div
                                class="h-3 overflow-hidden rounded-full bg-muted"
                            >
                                <div
                                    class="h-full rounded-full bg-emerald-500"
                                    :style="{
                                        width: `${Math.min(100, finances.contributions.collection_rate)}%`,
                                    }"
                                />
                            </div>
                        </div>
                        <div
                            class="flex items-center justify-between rounded-lg border p-4"
                        >
                            <div class="flex items-center gap-3">
                                <CircleAlert class="size-5 text-amber-600" />
                                <div>
                                    <p class="font-medium">
                                        Überfällige Forderungen
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        Aktuell offen und bereits fällig
                                    </p>
                                </div>
                            </div>
                            <div class="text-right">
                                <strong class="tabular-nums">{{
                                    money(finances.contributions.overdue_cents)
                                }}</strong>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        number(
                                            finances.contributions
                                                .overdue_count,
                                        )
                                    }}
                                    Vorgänge
                                </p>
                            </div>
                        </div></CardContent
                    ></Card
                >
                <Card class="gap-0 py-0"
                    ><CardHeader class="border-b py-5"
                        ><CardTitle class="text-base">Spendenarten</CardTitle
                        ><CardDescription
                            >Volumen und Anzahl im Zeitraum</CardDescription
                        ></CardHeader
                    ><CardContent class="divide-y py-1"
                        ><div
                            v-for="item in finances.donations.by_type"
                            :key="item.label"
                            class="flex items-center justify-between gap-4 py-4"
                        >
                            <div class="flex items-center gap-3">
                                <HeartHandshake
                                    class="size-5 text-muted-foreground"
                                />
                                <div>
                                    <p class="font-medium">{{ item.label }}</p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ number(item.count) }} Buchungen
                                    </p>
                                </div>
                            </div>
                            <strong class="tabular-nums">{{
                                money(item.amount_cents)
                            }}</strong>
                        </div>
                        <p
                            v-if="finances.donations.by_type.length === 0"
                            class="py-6 text-sm text-muted-foreground"
                        >
                            Keine Spenden im gewählten Zeitraum.
                        </p></CardContent
                    ></Card
                >
            </div>
        </template>

        <template v-else>
            <Card class="gap-0 overflow-hidden py-0"
                ><CardContent
                    class="grid gap-6 p-5 sm:grid-cols-[auto_1fr] sm:items-center"
                    ><div
                        class="relative grid size-32 place-items-center rounded-full"
                        :style="{
                            background: `conic-gradient(var(--primary) ${dataQuality.score}%, var(--muted) 0)`,
                        }"
                    >
                        <div
                            class="grid size-24 place-items-center rounded-full bg-card text-center"
                        >
                            <div>
                                <strong class="text-3xl tabular-nums"
                                    >{{ dataQuality.score }} %</strong
                                >
                                <p class="text-xs text-muted-foreground">
                                    Vollständig
                                </p>
                            </div>
                        </div>
                    </div>
                    <div>
                        <h2 class="text-lg font-semibold">
                            Datenbestand zum Stichtag
                        </h2>
                        <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                            Der Wert berücksichtigt E-Mail-Adresse,
                            Geburtsdatum, Geschlecht, Anschrift und Zahlungsart
                            aller aktiven Mitglieder. Das SEPA-Mandat wird
                            zusätzlich geprüft, sofern Lastschrift gewählt
                            wurde.
                        </p>
                    </div></CardContent
                ></Card
            >
            <div class="grid gap-4 lg:grid-cols-2">
                <Card
                    v-for="check in dataQuality.checks"
                    :key="check.key"
                    class="gap-0 py-0"
                    ><CardContent class="flex items-start gap-4 p-5"
                        ><div
                            class="rounded-lg p-2"
                            :class="
                                check.count
                                    ? 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300'
                                    : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
                            "
                        >
                            <CircleAlert
                                v-if="check.count"
                                class="size-5"
                            /><CircleCheck v-else class="size-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h2 class="font-semibold">
                                        {{ check.label }}
                                    </h2>
                                    <p
                                        class="mt-1 text-sm text-muted-foreground"
                                    >
                                        {{ check.description }}
                                    </p>
                                </div>
                                <Badge
                                    :variant="
                                        check.count ? 'secondary' : 'outline'
                                    "
                                    class="shrink-0 tabular-nums"
                                    >{{ number(check.count) }}</Badge
                                >
                            </div>
                            <div
                                class="mt-4 h-2 overflow-hidden rounded-full bg-muted"
                            >
                                <div
                                    class="h-full rounded-full"
                                    :class="
                                        check.count
                                            ? 'bg-amber-500'
                                            : 'bg-emerald-500'
                                    "
                                    :style="{
                                        width: `${check.count ? Math.max(2, check.percentage) : 100}%`,
                                    }"
                                />
                            </div>
                            <p
                                class="mt-1.5 text-right text-xs text-muted-foreground"
                            >
                                {{ check.percentage }} % betroffen
                            </p>
                        </div></CardContent
                    ></Card
                >
            </div>
        </template>
    </div>
</template>
