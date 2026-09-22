<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import { edit } from '@/routes/appearance';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Erscheinungsbild',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Erscheinungsbild" />

    <h1 class="sr-only">Erscheinungsbild</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Erscheinungsbild"
            description="Wähle das Erscheinungsbild für deinen Account"
        />
        <AppearanceTabs />
    </div>
</template>
