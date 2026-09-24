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
        <Link
            href="/formulare/quittungen"
            class="block max-w-lg space-y-2 rounded-xl border bg-card p-6 transition-colors hover:bg-muted"
            ><h2 class="text-xl font-semibold">Quittung</h2>
            <p class="text-sm text-muted-foreground">
                Zahlungseingang bestätigen, unterschreiben und als Original oder
                Kopie herunterladen. Ausgestellte Quittungen suchen und per
                E-Mail versenden.
            </p>
            <span class="inline-block pt-2 text-sm font-medium"
                >Quittungen öffnen →</span
            ></Link
        >
    </div>
</template>
