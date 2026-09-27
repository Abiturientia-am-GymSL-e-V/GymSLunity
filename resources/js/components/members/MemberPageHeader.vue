<script setup lang="ts">
import { Badge } from '@/components/ui/badge';

defineProps<{
    totalMembers: number;
    description: string;
}>();

const number = new Intl.NumberFormat('de-DE');
</script>

<template>
    <header>
        <div class="flex items-center gap-3">
            <h1 class="text-2xl font-semibold tracking-tight">Mitglieder</h1>
            <Badge variant="secondary" class="tabular-nums">
                {{ number.format(totalMembers) }}
            </Badge>
        </div>
        <p class="mt-1 text-sm text-muted-foreground">
            {{ description }}
        </p>
    </header>
</template>
