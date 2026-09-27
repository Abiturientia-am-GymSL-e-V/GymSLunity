<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Formulare' }],
    },
});
</script>

<template>
    <Head title="Formulare" />

    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Formulare</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Vereinsformulare erstellen, verwalten und bereitstellen.
            </p>
        </div>
        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            <Link
                href="/formulare/quittungen"
                class="block space-y-2 rounded-xl border bg-card p-6 transition-colors hover:bg-muted"
                ><h2 class="text-xl font-semibold">Quittungen</h2>
                <p class="text-sm text-muted-foreground">
                    Zahlungseingänge bestätigen, ausgeben und verwalten.
                </p>
                <span class="inline-block pt-2 text-sm font-medium"
                    >Quittungen öffnen →</span
                >
            </Link>
            <Link
                href="/formulare/unterschriftslisten"
                class="block space-y-2 rounded-xl border bg-card p-6 transition-colors hover:bg-muted"
                ><h2 class="text-xl font-semibold">Unterschriftslisten</h2>
                <p class="text-sm text-muted-foreground">
                    Mitglieder und Spalten auswählen und eine druckfertige
                    PDF-Liste erstellen.
                </p>
                <span class="inline-block pt-2 text-sm font-medium"
                    >Liste erstellen →</span
                >
            </Link>
            <Link
                href="/formulare/sepa-mandate"
                class="block space-y-2 rounded-xl border bg-card p-6 transition-colors hover:bg-muted"
                ><h2 class="text-xl font-semibold">SEPA-Mandate</h2>
                <p class="text-sm text-muted-foreground">
                    Mandate mit eigener Referenz anlegen, versenden und
                    unterschreiben lassen.
                </p>
                <span class="inline-block pt-2 text-sm font-medium"
                    >Mandate öffnen →</span
                >
            </Link>
        </div>
    </div>
</template>
