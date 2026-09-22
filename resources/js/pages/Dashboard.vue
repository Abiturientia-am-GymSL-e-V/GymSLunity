<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, Palette, UserRound } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard } from '@/routes';
import { index as members } from '@/routes/members';
import { edit as appearance } from '@/routes/appearance';
import { edit as profile } from '@/routes/profile';

const page = usePage();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Übersicht', href: dashboard() }],
    },
});
</script>

<template>
    <Head title="Übersicht" />

    <div
        class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-8 p-6 lg:p-10"
    >
        <div class="space-y-3">
            <p class="text-sm font-medium text-muted-foreground">
                Dein Arbeitsbereich
            </p>
            <h1 class="text-3xl font-semibold tracking-tight">
                Willkommen, {{ page.props.auth.user.name }}.
            </h1>
            <p class="max-w-2xl leading-relaxed text-muted-foreground">
                Die neue Entwicklungsinstanz ist eingerichtet. Dein Profil, die
                Kontosicherheit und die Darstellung kannst du bereits anpassen.
            </p>
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <Card>
                <CardHeader>
                    <UserRound
                        class="mb-3 size-6 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <CardTitle>Dein Profil</CardTitle>
                    <CardDescription
                        >Name und E-Mail-Adresse verwalten.</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <Button variant="outline" as-child>
                        <Link :href="profile()">
                            Profil bearbeiten
                            <ArrowRight class="size-4" aria-hidden="true" />
                        </Link>
                    </Button>
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <Palette
                        class="mb-3 size-6 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <CardTitle>Deine Darstellung</CardTitle>
                    <CardDescription
                        >Hell, dunkel oder passend zu deinem
                        System.</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <Button variant="outline" as-child>
                        <Link :href="appearance()">
                            Darstellung anpassen
                            <ArrowRight class="size-4" aria-hidden="true" />
                        </Link>
                    </Button>
                </CardContent>
            </Card>
        </div>

        <div v-if="page.props.can.viewMembers" class="rounded-xl border p-6">
            <h2 class="font-medium">Deine Mitglieder im Überblick</h2>
            <p
                class="mt-2 mb-5 max-w-2xl text-sm leading-relaxed text-muted-foreground"
            >
                Mitglieder finden und nach Zusatzfeldern, Mitgliedschaft oder
                Vereinsfunktion filtern.
            </p>
            <Button as-child>
                <Link :href="members()"
                    >Mitglieder öffnen
                    <ArrowRight class="size-4" aria-hidden="true"
                /></Link>
            </Button>
        </div>
    </div>
</template>
