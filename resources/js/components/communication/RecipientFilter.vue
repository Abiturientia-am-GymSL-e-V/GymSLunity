<script setup lang="ts">
import { RotateCcw, Search } from '@lucide/vue';
import MemberFilterBuilder from '@/components/members/MemberFilterBuilder.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { MemberFilter, MemberFilterField } from '@/types/members';

/**
 * Search and field filters for recipients, built like the signature list
 * selection. The page decides when to apply them.
 */
defineProps<{ fields: MemberFilterField[]; hasFilters: boolean }>();
const search = defineModel<string>('search', { required: true });
const filters = defineModel<MemberFilter[]>('filters', { required: true });
const emit = defineEmits<{ apply: []; reset: [] }>();
</script>

<template>
    <section
        class="space-y-4 rounded-xl border bg-card p-4 sm:p-5"
        aria-label="Empfänger filtern"
    >
        <div class="flex flex-wrap items-center gap-3">
            <div class="relative min-w-0 flex-1 basis-72 sm:max-w-md">
                <Label for="communication-search" class="sr-only"
                    >Empfänger suchen</Label
                ><Search
                    class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                /><Input
                    id="communication-search"
                    v-model="search"
                    type="search"
                    maxlength="120"
                    placeholder="Name, Mitgliedsnummer, E-Mail oder Ort …"
                    class="pl-9"
                    @keydown.enter.prevent="emit('apply')"
                />
            </div>
            <Button variant="outline" @click="emit('apply')"
                >Filter anwenden</Button
            ><Button v-if="hasFilters" variant="ghost" @click="emit('reset')"
                ><RotateCcw /> Zurücksetzen</Button
            >
        </div>
        <MemberFilterBuilder
            v-model="filters"
            :fields="fields"
            id-prefix="recipient-filter"
            presence-options
        />
    </section>
</template>
