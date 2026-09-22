<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Über GymSLunity' }],
    },
});
</script>

<template>
    <Head title="Über GymSLunity" />
    <div class="mx-auto w-full max-w-6xl space-y-4 p-4 sm:p-6">
        <h1 class="text-2xl font-semibold tracking-tight">Über GymSLunity</h1>
        <hr />
        <div class="flex justify-center">
            <img
                src="/images/gymslunity-logo.png"
                alt="GymSLunity"
                class="h-auto max-h-48 max-w-full object-contain"
            />
        </div>
        <div class="flex justify-center">
            <a href="https://gymslunity.de"
                ><img
                    alt="Current Release"
                    src="https://img.shields.io/github/v/release/Abiturientia-am-GymSL-e-V/GymSLunity"
                    style="max-width: 100%"
            /></a>
        </div>
        <hr />
        <p class="max-w-2xl text-muted-foreground">
            GymSLunity unterstützt Vereine bei der Verwaltung ihrer Mitglieder
            und Vereinsdaten.
        </p>
    </div>
</template>
