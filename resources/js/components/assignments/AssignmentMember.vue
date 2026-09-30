<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { assignmentPeriod } from '@/lib/memberFormatting';
import { show } from '@/routes/members';
import type { AssignmentRow } from '@/types/assignments';

/** Member with period and note of one assignment in an overview. */
defineProps<{
    row: AssignmentRow;
    type: 'department' | 'office' | 'honor';
    hidePeriod?: boolean;
}>();
</script>

<template>
    <div class="min-w-0">
        <p class="flex flex-wrap items-baseline gap-x-2">
            <Link
                :href="show(row.member_number)"
                class="font-medium break-words hover:underline"
                >{{ row.name }}</Link
            >
            <span class="text-xs text-muted-foreground">{{
                row.member_number
            }}</span>
            <span
                v-if="!row.current_member"
                class="text-xs text-muted-foreground"
                >kein aktuelles Mitglied</span
            >
        </p>
        <p
            v-if="!hidePeriod || row.note"
            class="text-sm break-words text-muted-foreground"
        >
            <span v-if="!hidePeriod">{{ assignmentPeriod(row, type) }}</span>
            <span v-if="!hidePeriod && row.note"> · </span>
            <span v-if="row.note">{{ row.note }}</span>
        </p>
    </div>
</template>
