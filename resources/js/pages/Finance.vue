<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Buchhaltung' }],
    },
});
</script>

<template>
    <Head title="Buchhaltung" />

    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Buchhaltung</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Einnahmen, Ausgaben und Belege zentral verwalten.
            </p>
        </div>
    </div>
</template>
