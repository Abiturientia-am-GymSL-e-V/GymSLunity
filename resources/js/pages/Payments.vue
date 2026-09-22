<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Beiträge' }],
    },
});
</script>

<template>
    <Head title="Beiträge" />
    <div class="mx-auto w-full max-w-6xl p-4 sm:p-6">
        <h1 class="text-2xl font-semibold">Beiträge</h1>
    </div>
</template>
