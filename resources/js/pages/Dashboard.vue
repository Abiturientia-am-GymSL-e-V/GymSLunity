<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    CakeSlice,
    CalendarDays,
    CircleAlert,
    HandCoins,
    HeartHandshake,
    UsersRound,
} from '@lucide/vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { dashboard, donations, payments } from '@/routes';
import { index as members, show as member } from '@/routes/members';
import { formatMoney } from '@/lib/format';

type MembershipCount = { label: string; count: number };
type MemberOverview = {
    current_count: number;
    joined_this_year: number;
    left_this_year: number;
    by_membership_type: MembershipCount[];
};
type Birthday = {
    member_number: number;
    name: string;
    date: string;
    days_from_today: number;
    age: number;
};
type ContributionOverview = {
    year: number;
    assessed_count: number;
    assessed_cents: number;
    paid_cents: number;
    collection_rate: number;
    open_count: number;
    open_cents: number;
    overdue_count: number;
    overdue_cents: number;
};
type DonationOverview = {
    year: number;
    count: number;
    amount_cents: number;
    average_cents: number;
    open_certificate_count: number;
    latest: {
        donor_name: string;
        amount_cents: number;
        donated_at: string;
    } | null;
};
type UpcomingEvent = {
    id: number;
    title: string;
    location: string | null;
    starts_at: string;
    ends_at: string;
    all_day: boolean;
    calendar_name: string;
    color: string;
};

const props = defineProps<{
    today: string;
    memberOverview: MemberOverview | null;
    birthdays: Birthday[] | null;
    contributionOverview: ContributionOverview | null;
    donationOverview: DonationOverview | null;
    upcomingEvents: UpcomingEvent[] | null;
}>();
const page = usePage();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Übersicht', href: dashboard() }],
    },
});

const longDate = (value: string) =>
    new Intl.DateTimeFormat('de-DE', {
        weekday: 'long',
        day: '2-digit',
        month: 'long',
        year: 'numeric',
    }).format(new Date(`${value}T00:00:00`));
const birthdayDay = (value: string) =>
    new Intl.DateTimeFormat('de-DE', { day: '2-digit' }).format(
        new Date(`${value}T00:00:00`),
    );
const birthdayMonth = (value: string) =>
    new Intl.DateTimeFormat('de-DE', { month: 'short' })
        .format(new Date(`${value}T00:00:00`))
        .replace('.', '');
const relativeBirthday = (days: number) => {
    if (days === 0) return 'Heute';
    if (days === 1) return 'Morgen';
    if (days === -1) return 'Gestern';
    return days > 0 ? `In ${days} Tagen` : `Vor ${Math.abs(days)} Tagen`;
};
const percentage = (value: number, total: number) =>
    total > 0 ? Math.min(100, Math.round((value / total) * 100)) : 0;
const eventDate = (event: UpcomingEvent) => {
    const start = new Date(event.starts_at);
    const day = new Intl.DateTimeFormat('de-DE', {
        weekday: 'short',
        day: '2-digit',
        month: '2-digit',
    }).format(start);
    return event.all_day
        ? `${day} · ganztägig`
        : `${day} · ${new Intl.DateTimeFormat('de-DE', { hour: '2-digit', minute: '2-digit' }).format(start)} Uhr`;
};
</script>

<template>
    <Head title="Übersicht" />

    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm font-medium text-muted-foreground">
                    {{ longDate(today) }}
                </p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight">
                    Guten Tag, {{ page.props.auth.user.name }}.
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Das Wichtigste aus dem Verein auf einen Blick.
                </p>
            </div>
        </header>

        <div
            v-if="
                !memberOverview &&
                !birthdays &&
                !upcomingEvents &&
                !contributionOverview &&
                !donationOverview
            "
            class="rounded-xl border bg-card p-8 text-center"
        >
            <p class="font-medium">Keine Kennzahlen verfügbar</p>
            <p class="mt-1 text-sm text-muted-foreground">
                Für {{ $address('deinen', 'Ihren') }} Zugang sind derzeit keine
                Verwaltungsbereiche freigeschaltet.
            </p>
        </div>

        <div class="grid items-start gap-5 xl:grid-cols-2">
            <Card
                v-if="memberOverview"
                class="gap-0 overflow-hidden py-0 xl:col-start-1 xl:row-start-1"
            >
                <CardHeader class="border-b py-5">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex gap-3">
                            <span
                                class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300"
                            >
                                <UsersRound class="size-5" aria-hidden="true" />
                            </span>
                            <div>
                                <CardTitle>Mitglieder</CardTitle>
                                <CardDescription
                                    >Derzeitiger
                                    Mitgliederbestand</CardDescription
                                >
                            </div>
                        </div>
                        <Link
                            :href="members()"
                            class="flex shrink-0 items-center gap-1 text-sm font-medium text-muted-foreground hover:text-foreground"
                        >
                            Öffnen<ArrowRight class="size-4" />
                        </Link>
                    </div>
                </CardHeader>
                <CardContent class="space-y-6 py-6">
                    <div class="flex flex-wrap items-end gap-x-8 gap-y-4">
                        <div>
                            <p class="text-4xl font-semibold tracking-tight">
                                {{ memberOverview.current_count }}
                            </p>
                            <p class="mt-1 text-sm text-muted-foreground">
                                aktuelle Mitglieder
                            </p>
                        </div>
                        <div class="grid grid-cols-2 gap-6 text-sm">
                            <div>
                                <p
                                    class="text-xl font-semibold text-emerald-700 dark:text-emerald-400"
                                >
                                    +{{ memberOverview.joined_this_year }}
                                </p>
                                <p class="text-muted-foreground">
                                    Eintritte
                                    {{ new Date(today).getFullYear() }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xl font-semibold">
                                    {{ memberOverview.left_this_year }}
                                </p>
                                <p class="text-muted-foreground">
                                    Austritte
                                    {{ new Date(today).getFullYear() }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="memberOverview.by_membership_type.length"
                        class="space-y-3 border-t pt-5"
                    >
                        <p
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            Nach Mitgliedsart
                        </p>
                        <div
                            v-for="entry in memberOverview.by_membership_type"
                            :key="entry.label"
                            class="grid grid-cols-[minmax(8rem,1fr)_minmax(6rem,2fr)_3rem] items-center gap-3 text-sm"
                        >
                            <span class="truncate" :title="entry.label">{{
                                entry.label
                            }}</span>
                            <span
                                class="h-2 overflow-hidden rounded-full bg-muted"
                            >
                                <span
                                    class="block h-full rounded-full bg-blue-500"
                                    :style="{
                                        width: `${percentage(entry.count, memberOverview.current_count)}%`,
                                    }"
                                ></span>
                            </span>
                            <span class="text-right font-medium">{{
                                entry.count
                            }}</span>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card
                v-if="birthdays"
                class="gap-0 overflow-hidden py-0 xl:col-start-2 xl:row-start-1"
            >
                <CardHeader class="border-b py-5">
                    <div class="flex items-start gap-3">
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-pink-100 text-pink-700 dark:bg-pink-950 dark:text-pink-300"
                        >
                            <CakeSlice class="size-5" aria-hidden="true" />
                        </span>
                        <div>
                            <CardTitle>Geburtstage</CardTitle>
                            <CardDescription
                                >14 Tage zurück und voraus</CardDescription
                            >
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="p-0">
                    <p
                        v-if="!birthdays.length"
                        class="p-8 text-center text-sm text-muted-foreground"
                    >
                        In diesem Zeitraum stehen keine Geburtstage an.
                    </p>
                    <ul v-else class="max-h-[25rem] divide-y overflow-y-auto">
                        <li
                            v-for="birthday in birthdays"
                            :key="birthday.member_number"
                        >
                            <Link
                                :href="member(birthday.member_number)"
                                class="flex items-center gap-4 px-6 py-3.5 transition-colors hover:bg-muted/60"
                            >
                                <span
                                    class="grid size-12 shrink-0 place-content-center rounded-lg border bg-background text-center leading-none"
                                >
                                    <strong class="text-lg">{{
                                        birthdayDay(birthday.date)
                                    }}</strong>
                                    <small
                                        class="mt-1 text-[10px] font-medium tracking-wide text-muted-foreground uppercase"
                                        >{{
                                            birthdayMonth(birthday.date)
                                        }}</small
                                    >
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-medium">{{
                                        birthday.name
                                    }}</span>
                                    <span
                                        class="mt-0.5 block text-sm text-muted-foreground"
                                        >{{ birthday.age }} Jahre</span
                                    >
                                </span>
                                <span
                                    class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium"
                                    :class="
                                        birthday.days_from_today === 0
                                            ? 'bg-pink-100 text-pink-800 dark:bg-pink-950 dark:text-pink-200'
                                            : 'bg-muted text-muted-foreground'
                                    "
                                >
                                    {{
                                        relativeBirthday(
                                            birthday.days_from_today,
                                        )
                                    }}
                                </span>
                            </Link>
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <Card
                v-if="upcomingEvents"
                class="gap-0 overflow-hidden py-0 xl:col-start-2 xl:row-start-2"
            >
                <CardHeader class="border-b py-5">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex gap-3">
                            <span
                                class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300"
                            >
                                <CalendarDays
                                    class="size-5"
                                    aria-hidden="true"
                                />
                            </span>
                            <div>
                                <CardTitle>Anstehende Termine</CardTitle>
                                <CardDescription
                                    >Die nächsten Vereinsevents</CardDescription
                                >
                            </div>
                        </div>
                        <Link
                            href="/kalender"
                            class="flex shrink-0 items-center gap-1 text-sm font-medium text-muted-foreground hover:text-foreground"
                        >
                            Öffnen<ArrowRight class="size-4" />
                        </Link>
                    </div>
                </CardHeader>
                <CardContent class="p-0">
                    <p
                        v-if="!upcomingEvents.length"
                        class="p-8 text-center text-sm text-muted-foreground"
                    >
                        Es stehen derzeit keine Termine an.
                    </p>
                    <ul v-else class="max-h-[25rem] divide-y overflow-y-auto">
                        <li
                            v-for="event in upcomingEvents"
                            :key="event.id"
                            class="flex gap-3 px-6 py-3.5"
                        >
                            <span
                                class="mt-1 size-3 shrink-0 rounded-full"
                                :style="{ backgroundColor: event.color }"
                            ></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium">{{
                                    event.title
                                }}</span>
                                <span
                                    class="mt-0.5 block text-sm text-muted-foreground"
                                >
                                    {{ eventDate(event)
                                    }}<template v-if="event.location">
                                        · {{ event.location }}</template
                                    >
                                </span>
                            </span>
                            <span
                                class="hidden shrink-0 text-xs text-muted-foreground sm:block"
                                >{{ event.calendar_name }}</span
                            >
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <Card
                v-if="contributionOverview"
                class="gap-0 overflow-hidden py-0 xl:col-start-1 xl:row-start-2"
            >
                <CardHeader class="border-b py-5">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex gap-3">
                            <span
                                class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
                            >
                                <HandCoins class="size-5" aria-hidden="true" />
                            </span>
                            <div>
                                <CardTitle>Beiträge</CardTitle>
                                <CardDescription
                                    >Beitragsjahr
                                    {{
                                        contributionOverview.year
                                    }}</CardDescription
                                >
                            </div>
                        </div>
                        <Link
                            :href="payments()"
                            class="flex shrink-0 items-center gap-1 text-sm font-medium text-muted-foreground hover:text-foreground"
                        >
                            Öffnen<ArrowRight class="size-4" />
                        </Link>
                    </div>
                </CardHeader>
                <CardContent class="space-y-6 py-6">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="rounded-lg bg-muted/60 p-4">
                            <p class="text-xs text-muted-foreground">
                                Festgesetzt
                            </p>
                            <p class="mt-1 text-xl font-semibold">
                                {{
                                    formatMoney(
                                        contributionOverview.assessed_cents,
                                    )
                                }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ contributionOverview.assessed_count }}
                                Beiträge
                            </p>
                        </div>
                        <div class="rounded-lg bg-muted/60 p-4">
                            <p class="text-xs text-muted-foreground">Bezahlt</p>
                            <p
                                class="mt-1 text-xl font-semibold text-emerald-700 dark:text-emerald-400"
                            >
                                {{
                                    formatMoney(contributionOverview.paid_cents)
                                }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ contributionOverview.collection_rate }} %
                                Quote
                            </p>
                        </div>
                        <div
                            class="rounded-lg p-4"
                            :class="
                                contributionOverview.open_count
                                    ? 'bg-amber-50 dark:bg-amber-950/30'
                                    : 'bg-muted/60'
                            "
                        >
                            <p class="text-xs text-muted-foreground">
                                Offen gesamt
                            </p>
                            <p class="mt-1 text-xl font-semibold">
                                {{
                                    formatMoney(contributionOverview.open_cents)
                                }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ contributionOverview.open_count }} Beiträge
                            </p>
                        </div>
                    </div>

                    <div>
                        <div
                            class="mb-2 flex justify-between text-xs text-muted-foreground"
                        >
                            <span
                                >Zahlungsstand
                                {{ contributionOverview.year }}</span
                            >
                            <span
                                >{{
                                    contributionOverview.collection_rate
                                }}
                                %</span
                            >
                        </div>
                        <div
                            class="h-2.5 overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                class="h-full rounded-full bg-emerald-500"
                                :style="{
                                    width: `${Math.min(100, contributionOverview.collection_rate)}%`,
                                }"
                            ></div>
                        </div>
                    </div>

                    <Alert
                        v-if="contributionOverview.overdue_count"
                        variant="warning"
                    >
                        <CircleAlert />
                        <AlertDescription>
                            <strong
                                >{{
                                    contributionOverview.overdue_count
                                }}
                                überfällig</strong
                            >
                            ·
                            {{
                                formatMoney(contributionOverview.overdue_cents)
                            }}
                        </AlertDescription>
                    </Alert>
                </CardContent>
            </Card>

            <Card
                v-if="donationOverview"
                class="gap-0 overflow-hidden py-0 xl:col-start-1 xl:row-start-3"
            >
                <CardHeader class="border-b py-5">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex gap-3">
                            <span
                                class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-violet-100 text-violet-700 dark:bg-violet-950 dark:text-violet-300"
                            >
                                <HeartHandshake
                                    class="size-5"
                                    aria-hidden="true"
                                />
                            </span>
                            <div>
                                <CardTitle>Spenden</CardTitle>
                                <CardDescription
                                    >Spendenjahr
                                    {{ donationOverview.year }}</CardDescription
                                >
                            </div>
                        </div>
                        <Link
                            :href="donations()"
                            class="flex shrink-0 items-center gap-1 text-sm font-medium text-muted-foreground hover:text-foreground"
                        >
                            Öffnen<ArrowRight class="size-4" />
                        </Link>
                    </div>
                </CardHeader>
                <CardContent class="space-y-6 py-6">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="rounded-lg bg-muted/60 p-4 sm:col-span-2">
                            <p class="text-xs text-muted-foreground">
                                Spendenvolumen
                            </p>
                            <p class="mt-1 text-3xl font-semibold">
                                {{ formatMoney(donationOverview.amount_cents) }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                aus {{ donationOverview.count }} Spenden
                            </p>
                        </div>
                        <div class="rounded-lg bg-muted/60 p-4">
                            <p class="text-xs text-muted-foreground">
                                Durchschnitt
                            </p>
                            <p class="mt-1 text-xl font-semibold">
                                {{
                                    formatMoney(donationOverview.average_cents)
                                }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                je Spende
                            </p>
                        </div>
                    </div>

                    <div class="grid gap-4 border-t pt-5 sm:grid-cols-2">
                        <div class="flex items-start gap-3">
                            <CalendarDays
                                class="mt-0.5 size-5 text-muted-foreground"
                            />
                            <div>
                                <p class="text-sm font-medium">Letzte Spende</p>
                                <p
                                    v-if="donationOverview.latest"
                                    class="mt-1 text-sm text-muted-foreground"
                                >
                                    {{
                                        formatMoney(
                                            donationOverview.latest
                                                .amount_cents,
                                        )
                                    }}
                                    von
                                    {{ donationOverview.latest.donor_name }} am
                                    {{
                                        new Intl.DateTimeFormat('de-DE').format(
                                            new Date(
                                                `${donationOverview.latest.donated_at}T00:00:00`,
                                            ),
                                        )
                                    }}
                                </p>
                                <p
                                    v-else
                                    class="mt-1 text-sm text-muted-foreground"
                                >
                                    Noch keine Spende in diesem Jahr.
                                </p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <CircleAlert
                                class="mt-0.5 size-5 text-muted-foreground"
                            />
                            <div>
                                <p class="text-sm font-medium">
                                    Offene Bestätigungen
                                </p>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    {{
                                        donationOverview.open_certificate_count
                                    }}
                                    noch nicht ausgestellt
                                </p>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
