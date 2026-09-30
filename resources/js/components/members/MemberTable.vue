<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    SearchX,
    UsersRound,
} from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type {
    Member,
    MemberFilters,
    MemberSort,
    MemberField,
} from '@/types/members';
import type { MemberColumn, MemberColumnKey } from './columns';
import { rememberMemberList } from '@/lib/memberNavigation';
import { show } from '@/routes/members';
import { isTemporal, memberValue } from '@/lib/memberFormatting';

const page = usePage();
const returnTo = computed(() => page.url);
function openMember(event: MouseEvent, member: Member) {
    if (
        (event.target as HTMLElement).closest('a, button, input') ||
        window.getSelection()?.toString()
    )
        return;
    if (
        event.button !== 0 ||
        event.ctrlKey ||
        event.metaKey ||
        event.shiftKey ||
        event.altKey
    )
        return;
    const url = rememberMemberList();
    router.get(show.url(member.member_number), { return_to: url });
}

const props = defineProps<{
    members: Member[];
    definitions: MemberField[];
    columns: MemberColumn[];
    filters: MemberFilters;
    loading: boolean;
    filtered: boolean;
    selected: number[];
}>();

const emit = defineEmits<{
    sort: [key: MemberSort];
    reset: [];
    select: [number: number, checked: boolean];
    selectPage: [checked: boolean];
}>();
const allSelected = computed(
    () =>
        props.members.length > 0 &&
        props.members.every((member) =>
            props.selected.includes(member.member_number),
        ),
);
const someSelected = computed(() =>
    props.members.some((member) =>
        props.selected.includes(member.member_number),
    ),
);
function plainValue(member: Member, column: MemberColumnKey): string {
    const field = props.definitions.find((field) => field.key === column);
    if (field?.custom) {
        return memberValue(
            isTemporal(field)
                ? member.assignments?.[column]
                : member.custom_values?.[column],
            field,
        );
    }
    switch (column) {
        case 'number':
            return String(member.member_number);
        case 'honorary':
            return member.is_honorary ? 'Ja' : 'Nein';
        case 'birth_date':
        case 'joined_at':
        case 'left_at':
            return member[column]
                ? member[column].slice(0, 10).split('-').reverse().join('.')
                : '—';
        default:
            return '—';
    }
}

function membershipStyle(type: string): string {
    const colors = [
        'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200',
        'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200',
        'border-violet-200 bg-violet-50 text-violet-800 dark:border-violet-900 dark:bg-violet-950 dark:text-violet-200',
    ];
    const hash = Array.from(type).reduce(
        (sum, char) => sum + char.charCodeAt(0),
        0,
    );
    return colors[hash % colors.length];
}
</script>

<template>
    <div
        role="region"
        aria-label="Mitgliedertabelle, horizontal und vertikal scrollbar"
        tabindex="0"
        class="max-h-[65vh] overflow-auto outline-offset-[-2px]"
        :aria-busy="loading"
        data-member-scroll
        scroll-region
    >
        <table
            class="w-full text-left text-sm"
            :class="{ 'opacity-60': loading }"
            data-test="member-table"
        >
            <caption class="sr-only">
                Mitglieder mit Kontaktinformationen, Zusatzfeldern und
                Mitgliedschaft
            </caption>
            <thead class="sticky top-0 z-10 bg-muted">
                <tr class="border-b">
                    <th scope="col" class="w-12 px-4">
                        <input
                            type="checkbox"
                            class="size-4 accent-primary"
                            aria-label="Alle Mitglieder dieser Seite auswählen"
                            :checked="allSelected"
                            :indeterminate="someSelected && !allSelected"
                            :disabled="loading || !members.length"
                            @change="
                                emit(
                                    'selectPage',
                                    ($event.target as HTMLInputElement).checked,
                                )
                            "
                        />
                    </th>
                    <th
                        v-for="column in columns"
                        :key="column.key"
                        scope="col"
                        class="h-11 px-4 font-medium whitespace-nowrap text-muted-foreground"
                        :aria-sort="
                            column.sort
                                ? column.sort === filters.sort
                                    ? filters.direction === 'asc'
                                        ? 'ascending'
                                        : 'descending'
                                    : 'none'
                                : undefined
                        "
                    >
                        <button
                            v-if="column.sort"
                            type="button"
                            class="flex items-center gap-2 rounded-sm py-2 transition-colors hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
                            :aria-label="`${column.label} sortieren`"
                            :data-test="`sort-${column.sort}`"
                            @click="emit('sort', column.sort)"
                        >
                            <span
                                v-if="column.key === 'number'"
                                class="sm:hidden"
                                >Nr.</span
                            >
                            <span
                                :class="{
                                    'hidden sm:inline': column.key === 'number',
                                }"
                                >{{ column.label }}</span
                            >
                            <component
                                :is="
                                    column.sort === filters.sort
                                        ? filters.direction === 'asc'
                                            ? ArrowUp
                                            : ArrowDown
                                        : ArrowUpDown
                                "
                                class="size-3.5"
                                aria-hidden="true"
                            />
                        </button>
                        <template v-else>{{ column.label }}</template>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <tr
                    v-for="member in members"
                    :key="member.id"
                    class="cursor-pointer transition-colors focus-within:bg-muted/40 hover:bg-muted/40"
                    :data-member-number="member.member_number"
                    @click="openMember($event, member)"
                >
                    <td class="px-4" @click.stop>
                        <input
                            type="checkbox"
                            class="size-4 accent-primary"
                            :aria-label="`${member.first_name} ${member.last_name} auswählen`"
                            :checked="selected.includes(member.member_number)"
                            :disabled="loading"
                            @change="
                                emit(
                                    'select',
                                    member.member_number,
                                    ($event.target as HTMLInputElement).checked,
                                )
                            "
                        />
                    </td>
                    <td
                        v-for="column in columns"
                        :key="column.key"
                        class="px-4 py-4 align-middle"
                        :class="{
                            'font-mono text-xs text-muted-foreground tabular-nums':
                                column.key === 'number',
                        }"
                    >
                        <div
                            v-if="column.key === 'name'"
                            class="flex min-w-48 items-center gap-3"
                        >
                            <span
                                class="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium text-muted-foreground"
                                aria-hidden="true"
                                >{{ member.first_name.charAt(0)
                                }}{{ member.last_name.charAt(0) }}</span
                            >
                            <div class="min-w-0">
                                <Link
                                    :href="
                                        show.url(member.member_number, {
                                            query: { return_to: returnTo },
                                        })
                                    "
                                    class="block max-w-60 truncate rounded-sm font-medium outline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                                    :title="`${member.first_name} ${member.middle_name || ''} ${member.last_name}`"
                                    @click="rememberMemberList()"
                                >
                                    {{
                                        `${member.first_name} ${member.last_name}`
                                    }}
                                </Link>
                                <p
                                    v-if="member.is_honorary"
                                    class="mt-0.5 text-xs text-muted-foreground"
                                >
                                    Ehrenmitglied
                                </p>
                            </div>
                        </div>
                        <div
                            v-else-if="column.key === 'contact'"
                            class="min-w-44 space-y-1"
                        >
                            <p
                                class="max-w-60 truncate"
                                :title="member.email || undefined"
                            >
                                {{ member.email || '—' }}
                            </p>
                            <p
                                v-if="member.mobile_phone"
                                class="text-xs whitespace-nowrap text-muted-foreground"
                            >
                                {{ member.mobile_phone }}
                            </p>
                        </div>
                        <div
                            v-else-if="column.key === 'location'"
                            class="min-w-28 space-y-1"
                        >
                            <p class="whitespace-nowrap">
                                {{ member.city || '—' }}
                            </p>
                            <p
                                v-if="member.postal_code"
                                class="text-xs text-muted-foreground"
                            >
                                {{ member.postal_code }}
                            </p>
                        </div>
                        <div
                            v-else-if="column.key === 'address'"
                            class="min-w-32 space-y-1"
                        >
                            <p class="whitespace-nowrap">
                                {{ member.street || '—' }}
                            </p>
                            <p
                                v-if="member.country"
                                class="text-xs text-muted-foreground"
                            >
                                {{ member.country }}
                            </p>
                        </div>
                        <Badge
                            v-else-if="column.key === 'membership'"
                            variant="outline"
                            class="whitespace-nowrap"
                            :class="membershipStyle(member.membership_type)"
                            >{{
                                memberValue(
                                    member.membership_type,
                                    definitions.find(
                                        (field) =>
                                            field.key === 'membership_type',
                                    ),
                                )
                            }}</Badge
                        >
                        <span v-else class="whitespace-nowrap tabular-nums">{{
                            plainValue(member, column.key)
                        }}</span>
                    </td>
                </tr>
                <tr v-if="members.length === 0">
                    <td
                        :colspan="columns.length + 1"
                        class="px-6 py-16 text-center"
                    >
                        <component
                            :is="filtered ? SearchX : UsersRound"
                            class="mx-auto mb-4 size-8 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <p class="font-medium">
                            {{
                                filtered
                                    ? 'Keine Mitglieder gefunden'
                                    : 'Noch keine Mitglieder vorhanden'
                            }}
                        </p>
                        <p class="mt-2 text-sm text-muted-foreground">
                            {{
                                filtered
                                    ? $address(
                                          'Passe die Suche an oder setze die Filter zurück.',
                                          'Passen Sie die Suche an oder setzen Sie die Filter zurück.',
                                      )
                                    : 'Sobald Mitglieder angelegt sind, erscheinen sie in dieser Übersicht.'
                            }}
                        </p>
                        <Button
                            v-if="filtered"
                            variant="outline"
                            class="mt-5"
                            @click="emit('reset')"
                            >Filter zurücksetzen</Button
                        >
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
