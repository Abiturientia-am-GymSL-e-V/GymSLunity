<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, History } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    assignmentLines,
    memberTimestamp,
    memberValue,
} from '@/lib/memberFormatting';
import type {
    AssignmentSnapshot,
    MemberField,
    MemberHistory,
    MemberSection,
    MemberValue,
} from '@/types/members';

const props = defineProps<{
    history: MemberHistory;
    sections: MemberSection[];
    disabled: boolean;
}>();
const fields = computed(() =>
    Object.fromEntries(
        props.sections.flatMap((section) =>
            section.fields.map((field) => [field.key, field]),
        ),
    ),
);
/** Assignment lists appear line by line, all other values as one text. */
function lines(
    value: MemberValue | AssignmentSnapshot[] | undefined,
    field?: MemberField,
): string[] {
    return (
        assignmentLines(value, field) ?? [
            memberValue(Array.isArray(value) ? null : value, field),
        ]
    );
}
const loading = ref(false);
function visit(url: string | null) {
    if (!url || props.disabled || loading.value) return;
    router.get(
        url,
        {},
        {
            only: ['history'],
            preserveScroll: true,
            preserveState: true,
            replace: true,
            onStart: () => {
                loading.value = true;
            },
            onFinish: () => {
                loading.value = false;
            },
        },
    );
}
</script>

<template>
    <aside
        class="min-w-0 self-start rounded-xl border bg-card"
        aria-labelledby="history-title"
        data-test="member-history"
    >
        <div class="flex items-center gap-2 border-b p-5">
            <History class="size-4 text-muted-foreground" aria-hidden="true" />
            <h2 id="history-title" class="font-semibold">Änderungshistorie</h2>
            <span
                class="ml-auto rounded-full bg-muted px-2 py-0.5 text-xs tabular-nums"
                >{{ history.total }}</span
            >
        </div>
        <div
            v-if="!history.total"
            class="p-5 text-sm leading-relaxed text-muted-foreground"
        >
            Noch keine Änderungen protokolliert. Künftige Änderungen erscheinen
            hier mit Zeitpunkt, Benutzer und den geänderten Werten.
        </div>
        <ol v-else class="divide-y" :aria-busy="loading">
            <li
                v-for="entry in history.data"
                :key="entry.id"
                class="space-y-3 p-5"
            >
                <div>
                    <p class="text-sm font-medium break-words">
                        {{ entry.actor_name }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{
                            `${memberTimestamp(entry.created_at)} · Version ${entry.version}`
                        }}
                    </p>
                </div>
                <details class="group" :open="entry.id === history.data[0]?.id">
                    <summary
                        class="cursor-pointer rounded text-xs font-medium text-muted-foreground outline-offset-4 hover:text-foreground"
                    >
                        {{
                            `${entry.changed_fields.length} ${entry.changed_fields.length === 1 ? 'Feld geändert' : 'Felder geändert'}`
                        }}
                    </summary>
                    <dl class="mt-3 space-y-3">
                        <div
                            v-for="key in entry.changed_fields"
                            :key="key"
                            class="text-xs"
                        >
                            <dt class="font-medium">
                                {{
                                    entry.field_schema?.[key]?.label ||
                                    fields[key]?.label ||
                                    key
                                }}
                            </dt>
                            <dd class="mt-1 space-y-1 break-words">
                                <div
                                    v-for="side in [
                                        {
                                            label: 'Vorher:',
                                            value: entry.before[key],
                                            muted: true,
                                        },
                                        {
                                            label: 'Nachher:',
                                            value: entry.after[key],
                                            muted: false,
                                        },
                                    ]"
                                    :key="side.label"
                                    :class="{
                                        'text-muted-foreground': side.muted,
                                    }"
                                >
                                    <span class="mr-1 font-medium">{{
                                        side.label
                                    }}</span>
                                    <template
                                        v-for="(line, index) in lines(
                                            side.value,
                                            entry.field_schema?.[key] ||
                                                fields[key],
                                        )"
                                        :key="index"
                                        ><br v-if="index > 0" />{{
                                            line
                                        }}</template
                                    >
                                </div>
                            </dd>
                        </div>
                    </dl>
                </details>
            </li>
        </ol>
        <div
            v-if="history.last_page > 1"
            class="flex items-center justify-between gap-2 border-t p-4"
        >
            <Button
                type="button"
                variant="outline"
                size="icon-sm"
                aria-label="Neuere Änderungen"
                :disabled="!history.prev_page_url || disabled || loading"
                @click="visit(history.prev_page_url)"
                ><ChevronLeft class="size-4"
            /></Button>
            <span class="text-xs text-muted-foreground">{{
                `Seite ${history.current_page} von ${history.last_page}`
            }}</span>
            <Button
                type="button"
                variant="outline"
                size="icon-sm"
                aria-label="Ältere Änderungen"
                :disabled="!history.next_page_url || disabled || loading"
                @click="visit(history.next_page_url)"
                ><ChevronRight class="size-4"
            /></Button>
        </div>
    </aside>
</template>
