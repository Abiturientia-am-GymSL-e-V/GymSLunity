<script setup lang="ts">
import { Download } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { withQuery } from '@/lib/assignmentOverview';

/** CSV and PDF export of an overview with its current filters. */
const props = defineProps<{
    path: string;
    query: Record<string, string | number | boolean | null | undefined>;
    disabled?: boolean;
}>();
const url = (format: 'csv' | 'pdf') =>
    withQuery(props.path, { ...props.query, format });
const links = computed(() => [
    { format: 'csv' as const, label: 'CSV', href: url('csv') },
    { format: 'pdf' as const, label: 'PDF', href: url('pdf') },
]);
</script>

<template>
    <div class="flex flex-wrap gap-2">
        <template v-for="link in links" :key="link.format">
            <Button v-if="disabled" variant="outline" disabled>
                <Download class="size-4" />{{ link.label }}
            </Button>
            <Button v-else variant="outline" as-child>
                <a :href="link.href"
                    ><Download class="size-4" />{{ link.label }}</a
                >
            </Button>
        </template>
    </div>
</template>
