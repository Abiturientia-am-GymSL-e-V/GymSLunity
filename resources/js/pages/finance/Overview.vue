<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Buchhaltung' }],
    },
});
</script>

<template>
    <Head title="Buchhaltung" />

    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Buchhaltung</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Finanzvorgänge verwalten und offene Forderungen nachverfolgen.
            </p>
        </header>

        <Link
            href="/buchhaltung/rechnungen"
            class="block max-w-lg space-y-2 rounded-xl border bg-card p-6 transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
        >
            <h2 class="text-xl font-semibold">Rechnungswesen</h2>
            <p class="text-sm text-muted-foreground">
                Rechnungen mit Positionen und verschiedenen Zahlungsarten
                erstellen, als PDF oder XRechnung ausgeben, versenden und offene
                Forderungen verwalten.
            </p>
            <span class="inline-block pt-2 text-sm font-medium">
                Rechnungswesen öffnen →
            </span>
        </Link>
    </div>
</template>
