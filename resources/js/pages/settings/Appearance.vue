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

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Erscheinungsbild"
            :description="
                $address(
                    'Wähle das Erscheinungsbild für deinen Account',
                    'Wählen Sie das Erscheinungsbild für Ihren Account',
                )
            "
        />
        <AppearanceTabs />
    </div>
</template>
