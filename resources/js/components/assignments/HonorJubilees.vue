<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { reactive } from 'vue';
import BulkAssignDialog from '@/components/assignments/BulkAssignDialog.vue';
import { Badge } from '@/components/ui/badge';
import { day } from '@/lib/memberFormatting';
import { show } from '@/routes/members';
import type { JubileeGroup } from '@/types/assignments';
import type { AssignmentField } from '@/types/members';

/** Due jubilees per honor; selected members can receive the honor at once. */
const props = defineProps<{
    groups: JubileeGroup[];
    fields: AssignmentField[];
    canAssign: boolean;
    configurationVersion: number;
}>();

const selection = reactive<Record<string, number[]>>({});
const key = (group: JubileeGroup) => `${group.field}:${group.option}`;
function selected(group: JubileeGroup): number[] {
    return selection[key(group)] ?? [];
}
function toggle(group: JubileeGroup, number: number, checked: boolean) {
    const current = selected(group);
    selection[key(group)] = checked
        ? [...new Set([...current, number])]
        : current.filter((value) => value !== number);
}
function toggleAll(group: JubileeGroup, checked: boolean) {
    selection[key(group)] = checked
        ? group.members.map((member) => member.member_number)
        : [];
}
const showField = props.fields.length > 1;
</script>

<template>
    <section
        v-for="group in groups"
        :key="key(group)"
        class="overflow-hidden rounded-xl border bg-card"
    >
        <header
            class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4"
        >
            <div class="min-w-0">
                <h2 class="font-semibold">{{ group.label }}</h2>
                <p class="text-sm text-muted-foreground">
                    Nach {{ group.years }}
                    {{ group.years === 1 ? 'Mitgliedsjahr' : 'Mitgliedsjahren'
                    }}<template v-if="showField">
                        · {{ group.field_label }}</template
                    >
                    · {{ group.members.length }}
                    {{ group.members.length === 1 ? 'Mitglied' : 'Mitglieder' }}
                </p>
            </div>
            <BulkAssignDialog
                v-if="canAssign"
                :selected="selected(group)"
                :fields="fields"
                :configuration-version="configurationVersion"
                :preset="{
                    field: group.field,
                    option: group.option,
                    note: `${group.years} Jahre Mitgliedschaft`,
                }"
                button-label="Ehrung vergeben"
                @done="selection[key(group)] = []"
            />
        </header>
        <p
            v-if="!group.members.length"
            class="px-5 py-4 text-sm text-muted-foreground"
        >
            Niemand wird bis zum gewählten Datum fällig.
        </p>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th v-if="canAssign" class="w-10 px-5 py-3">
                            <input
                                type="checkbox"
                                class="size-4 accent-primary"
                                :checked="
                                    selected(group).length ===
                                    group.members.length
                                "
                                :aria-label="`Alle für ${group.label} auswählen`"
                                @change="
                                    toggleAll(
                                        group,
                                        ($event.target as HTMLInputElement)
                                            .checked,
                                    )
                                "
                            />
                        </th>
                        <th class="px-5 py-3 font-semibold">Mitglied</th>
                        <th class="px-5 py-3 font-semibold">Eintritt</th>
                        <th class="px-5 py-3 font-semibold">Jubiläum am</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="member in group.members"
                        :key="member.member_number"
                    >
                        <td v-if="canAssign" class="px-5 py-3">
                            <input
                                type="checkbox"
                                class="size-4 accent-primary"
                                :checked="
                                    selected(group).includes(
                                        member.member_number,
                                    )
                                "
                                :aria-label="`${member.name} auswählen`"
                                @change="
                                    toggle(
                                        group,
                                        member.member_number,
                                        ($event.target as HTMLInputElement)
                                            .checked,
                                    )
                                "
                            />
                        </td>
                        <td class="min-w-48 px-5 py-3">
                            <Link
                                :href="show(member.member_number)"
                                class="font-medium hover:underline"
                                >{{ member.name }}</Link
                            >
                            <span class="ml-2 text-xs text-muted-foreground">{{
                                member.member_number
                            }}</span>
                        </td>
                        <td class="px-5 py-3 whitespace-nowrap">
                            {{ day(member.joined_at) }}
                        </td>
                        <td class="px-5 py-3 whitespace-nowrap">
                            {{ day(member.jubilee_on) }}
                            <Badge
                                :variant="member.due ? 'default' : 'outline'"
                                class="ml-2"
                                >{{
                                    member.due ? 'Fällig' : 'Bevorstehend'
                                }}</Badge
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
