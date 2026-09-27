<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    Boxes,
    CalendarDays,
    CalendarRange,
    ChartNoAxesCombined,
    FileText,
    HandCoins,
    HeartHandshake,
    Landmark,
    LayoutGrid,
    MessageSquareText,
    UsersRound,
} from '@lucide/vue';
import ConfigurationNav from '@/components/configuration/ConfigurationNav.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Definition = {
    label: string;
    description: string;
    areas: string[];
};

const props = defineProps<{
    modules: Record<string, boolean>;
    definitions: Record<string, Definition>;
    version: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Konfiguration', href: '/konfiguration/verein' },
            { title: 'Softwaremodule' },
        ],
    },
});

const form = useForm({ modules: { ...props.modules }, version: props.version });
const icons = {
    payments: HandCoins,
    statistics: ChartNoAxesCombined,
    finance: Landmark,
    forms: FileText,
    donations: HeartHandshake,
    inventory: Boxes,
    calendar: CalendarDays,
    bookings: CalendarRange,
    communication: MessageSquareText,
};
const baseModules = [
    {
        key: 'dashboard',
        label: 'Dashboard',
        description:
            'Persönliche Übersicht und Einstiegspunkt der Vereinsverwaltung.',
        areas: ['Übersicht'],
        icon: LayoutGrid,
    },
    {
        key: 'members',
        label: 'Mitglieder',
        description:
            'Zentrale Mitgliederverwaltung und Grundlage der weiteren Fachmodule.',
        areas: ['Mitglieder', 'Beitrittsanträge', 'Import & Export'],
        icon: UsersRound,
    },
] as const;

function save() {
    form.patch('/konfiguration/softwaremodule', {
        preserveScroll: true,
        onSuccess: () => {
            form.version = props.version;
            form.defaults();
        },
    });
}
</script>

<template>
    <Head title="Softwaremodule" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Konfiguration</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ $address('Lege', 'Legen Sie') }} fest, welche
                Funktionsbereiche der Verein verwendet.
            </p>
        </header>
        <ConfigurationNav />

        <form class="space-y-6" @submit.prevent="save">
            <InputError :message="form.errors.version" />
            <section class="space-y-3">
                <div>
                    <h2 class="font-semibold">Basismodule</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Dashboard und Mitgliederverwaltung werden für den
                        Betrieb benötigt und sind immer aktiv.
                    </p>
                </div>
                <div class="grid gap-3 md:grid-cols-2">
                    <label
                        v-for="module in baseModules"
                        :key="module.key"
                        class="flex items-start gap-3 rounded-lg border bg-muted/20 p-4 text-sm"
                    >
                        <input
                            type="checkbox"
                            checked
                            disabled
                            class="mt-1 size-4 shrink-0 accent-primary"
                        />
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-2 font-medium">
                                <component
                                    :is="module.icon"
                                    class="size-4 text-muted-foreground"
                                />
                                {{ module.label }}
                                <Badge variant="secondary">Immer aktiv</Badge>
                            </span>
                            <span
                                class="mt-1 block text-xs leading-5 text-muted-foreground"
                                >{{ module.description }}</span
                            >
                            <span class="mt-2 flex flex-wrap gap-1">
                                <Badge
                                    v-for="area in module.areas"
                                    :key="area"
                                    variant="outline"
                                    class="font-normal"
                                    >{{ area }}</Badge
                                >
                            </span>
                        </span>
                    </label>
                </div>
            </section>

            <fieldset class="space-y-3">
                <legend class="font-semibold">Optionale Module</legend>
                <p class="text-sm text-muted-foreground">
                    Deaktivierte Module verschwinden aus der Navigation und vom
                    Dashboard. Ihre gespeicherten Daten bleiben erhalten.
                </p>
                <div class="grid gap-3 md:grid-cols-2">
                    <label
                        v-for="(definition, key) in definitions"
                        :key="key"
                        class="flex items-start gap-3 rounded-lg border p-4 text-sm transition-colors"
                        :class="
                            form.modules[key]
                                ? 'border-primary/40 bg-primary/5'
                                : 'bg-card'
                        "
                    >
                        <input
                            v-model="form.modules[key]"
                            type="checkbox"
                            class="mt-1 size-4 shrink-0 accent-primary"
                        />
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-2 font-medium">
                                <component
                                    :is="icons[key as keyof typeof icons]"
                                    class="size-4 text-muted-foreground"
                                />
                                {{ definition.label }}
                                <Badge
                                    :variant="
                                        form.modules[key]
                                            ? 'secondary'
                                            : 'outline'
                                    "
                                    >{{
                                        form.modules[key]
                                            ? 'Aktiv'
                                            : 'Deaktiviert'
                                    }}</Badge
                                >
                            </span>
                            <span
                                class="mt-1 block text-xs leading-5 text-muted-foreground"
                                >{{ definition.description }}</span
                            >
                            <span class="mt-2 flex flex-wrap gap-1">
                                <Badge
                                    v-for="area in definition.areas"
                                    :key="area"
                                    variant="outline"
                                    class="font-normal"
                                    >{{ area }}</Badge
                                >
                            </span>
                        </span>
                    </label>
                </div>
                <InputError :message="form.errors.modules" />
            </fieldset>

            <Button :disabled="form.processing">Module speichern</Button>
        </form>
    </div>
</template>
