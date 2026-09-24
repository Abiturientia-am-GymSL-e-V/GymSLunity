<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';

defineProps<{ selfserviceEnabled: boolean; publicJoinEnabled: boolean }>();
const page = usePage();
</script>

<template>
    <Head title="Willkommen" />

    <div class="flex min-h-svh flex-col bg-background text-foreground">
        <header class="mx-auto flex w-full max-w-6xl items-center px-6 py-8">
            <div class="w-52">
                <AppLogo />
            </div>
        </header>

        <main
            class="mx-auto flex w-full max-w-6xl flex-1 items-center px-6 py-16 sm:py-24"
        >
            <div class="max-w-2xl space-y-8">
                <span
                    class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-medium text-muted-foreground"
                >
                    <span
                        class="size-1.5 rounded-full bg-emerald-500"
                        aria-hidden="true"
                    />
                    Mitgliederverwaltung
                </span>
                <div class="space-y-5">
                    <h1
                        class="text-4xl leading-tight font-semibold tracking-tight sm:text-6xl"
                    >
                        Mehr Raum für<br />unseren Verein.
                    </h1>
                    <p
                        class="max-w-lg text-lg leading-relaxed text-muted-foreground"
                    >
                        Dein Verein, deine Daten. Hier findest du den Zugang zur
                        Verwaltung und zum Mitgliederbereich.
                    </p>
                </div>
                <Button size="lg" as-child>
                    <Link :href="page.props.auth.user ? dashboard() : login()">
                        {{
                            page.props.auth.user
                                ? 'Zur Verwaltung'
                                : 'Verwaltung anmelden'
                        }}
                        <ArrowRight class="size-4" aria-hidden="true" />
                    </Link>
                </Button>
                <div v-if="selfserviceEnabled" class="flex flex-wrap gap-3">
                    <Button as-child variant="outline" size="lg"
                        ><Link href="/selfservice/zugang"
                            >Mitgliederzugang</Link
                        ></Button
                    >
                    <Button
                        v-if="publicJoinEnabled"
                        as-child
                        variant="outline"
                        size="lg"
                        ><Link href="/selfservice/zugang?beitritt=1"
                            >Mitglied werden</Link
                        ></Button
                    >
                </div>
            </div>
        </main>

        <footer
            class="mx-auto w-full max-w-6xl px-6 py-6 text-sm text-muted-foreground"
        >
            GymSLunity · Gemeinsam organisiert.
        </footer>
    </div>
</template>
