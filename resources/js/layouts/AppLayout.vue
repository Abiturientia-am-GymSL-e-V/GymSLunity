<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

const props = withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    { breadcrumbs: () => [] },
);
const page = usePage<{
    navigationBreadcrumb?: BreadcrumbItem;
}>();
const breadcrumbs = computed(() => {
    const current = page.props.navigationBreadcrumb;

    return current ? [...props.breadcrumbs, current] : props.breadcrumbs;
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <slot />
    </AppLayout>
</template>
