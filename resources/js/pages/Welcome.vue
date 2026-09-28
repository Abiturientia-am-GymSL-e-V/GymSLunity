<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, UserPlus } from '@lucide/vue';
import PublicFooter from '@/components/public/PublicFooter.vue';
import PublicHeader from '@/components/public/PublicHeader.vue';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';

defineProps<{ selfserviceEnabled: boolean; publicJoinEnabled: boolean }>();
const page = usePage();
</script>

<template>
    <Head title="Willkommen" />

    <div class="flex min-h-svh flex-col bg-background text-foreground">
        <PublicHeader />

        <main
            class="mx-auto flex w-full max-w-6xl flex-1 items-center px-6 py-16 sm:py-24"
        >
            <div class="max-w-2xl space-y-8">
                <div class="space-y-5">
                    <img
                        v-if="page.props.logoUrl"
                        :src="page.props.logoUrl"
                        :alt="`${page.props.clubName ?? 'Verein'} – Vereinslogo`"
                        class="max-h-40 max-w-full object-contain object-left"
                    />

                    <h1
                        v-else-if="page.props.clubName"
                        class="text-4xl leading-tight font-semibold tracking-tight sm:text-6xl"
                    >
                        {{ page.props.clubName }}
                    </h1>

                    <h1
                        v-else
                        class="text-4xl leading-tight font-semibold tracking-tight sm:text-6xl"
                    >
                        Mehr Raum für<br />unseren Verein.
                    </h1>
                    <p
                        class="max-w-lg text-lg leading-relaxed text-muted-foreground"
                    >
                        {{
                            $address(
                                'Dein Verein, deine Daten. Hier findest du den Zugang zur Verwaltung und zum Mitgliederbereich.',
                                'Ihr Verein, Ihre Daten. Hier finden Sie den Zugang zur Verwaltung und zum Mitgliederbereich.',
                            )
                        }}
                    </p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <Button size="lg" as-child>
                        <Link
                            :href="page.props.auth.user ? dashboard() : login()"
                        >
                            Verwaltung
                            <ArrowRight class="size-4" aria-hidden="true" />
                        </Link>
                    </Button>
                    <Button
                        v-if="selfserviceEnabled"
                        as-child
                        variant="outline"
                        size="lg"
                    >
                        <Link href="/selfservice/zugang">
                            Mitgliederportal
                            <ArrowRight class="size-4" aria-hidden="true" />
                        </Link>
                    </Button>
                </div>
                <Button
                    v-if="selfserviceEnabled && publicJoinEnabled"
                    as-child
                    size="lg"
                    class="bg-emerald-600 text-white hover:bg-emerald-700"
                    ><Link href="/selfservice/mitglied-werden"
                        ><UserPlus class="size-4" aria-hidden="true" />Mitglied
                        werden</Link
                    ></Button
                >
            </div>
        </main>

        <PublicFooter />
    </div>
</template>
