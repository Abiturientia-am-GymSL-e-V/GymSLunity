<script setup lang="ts">
import { RotateCcw, Search } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    RecipientFilterOptions,
    RecipientFilters,
} from '@/types/communication';
import type { MemberField } from '@/types/members';

/**
 * The filter form edits the page's reactive draft in place; the page
 * decides when to apply it.
 */
defineProps<{
    draft: RecipientFilters;
    filterOptions: RecipientFilterOptions;
    customFilters: MemberField[];
    hasFilters: boolean;
}>();
const emit = defineEmits<{ apply: []; reset: [] }>();
const selectClass =
    'h-9 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus:border-ring focus:ring-2 focus:ring-ring/30';
</script>

<template>
    <section
        class="rounded-xl border bg-card p-4 sm:p-5"
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
                    v-model="draft.q"
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
        <div
            class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
            <div class="grid gap-2">
                <Label for="filter-status">Mitgliedsstatus</Label
                ><select
                    id="filter-status"
                    v-model="draft.status"
                    :class="selectClass"
                >
                    <option value="active">Aktive Mitglieder</option>
                    <option value="contacts">Kontakte</option>
                    <option value="former">Ausgetreten / verstorben</option>
                    <option value="future">Künftige Eintritte</option>
                    <option value="all">Alle Datensätze</option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="filter-membership">Mitgliedsart</Label
                ><select
                    id="filter-membership"
                    v-model="draft.membership"
                    :class="selectClass"
                >
                    <option value="">Alle</option>
                    <option
                        v-for="item in filterOptions.memberships"
                        :key="item"
                        :value="item"
                    >
                        {{ item }}
                    </option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="filter-department">Funktion Abteilung</Label
                ><select
                    id="filter-department"
                    v-model="draft.department_role"
                    :class="selectClass"
                >
                    <option value="">Alle</option>
                    <option value="__any__">Mit Funktion</option>
                    <option value="__none__">Ohne Funktion</option>
                    <option
                        v-for="item in filterOptions.departmentRoles"
                        :key="item"
                        :value="item"
                    >
                        {{ item }}
                    </option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="filter-club-role">Funktion Hauptverein</Label
                ><select
                    id="filter-club-role"
                    v-model="draft.club_role"
                    :class="selectClass"
                >
                    <option value="">Alle</option>
                    <option value="__any__">Mit Funktion</option>
                    <option value="__none__">Ohne Funktion</option>
                    <option
                        v-for="item in filterOptions.clubRoles"
                        :key="item"
                        :value="item"
                    >
                        {{ item }}
                    </option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="filter-gender">Geschlecht</Label
                ><select
                    id="filter-gender"
                    v-model="draft.gender"
                    :class="selectClass"
                >
                    <option value="">Alle</option>
                    <option value="w">Weiblich</option>
                    <option value="m">Männlich</option>
                    <option value="d">Divers</option>
                    <option value="o">Ohne Angabe</option>
                    <option value="__none__">Nicht hinterlegt</option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="filter-payment">Zahlungsart</Label
                ><select
                    id="filter-payment"
                    v-model="draft.payment_method"
                    :class="selectClass"
                >
                    <option value="">Alle</option>
                    <option
                        v-for="item in filterOptions.paymentMethods"
                        :key="item"
                        :value="item"
                    >
                        {{ item }}
                    </option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="filter-city">Wohnort</Label
                ><select
                    id="filter-city"
                    v-model="draft.city"
                    :class="selectClass"
                >
                    <option value="">Alle</option>
                    <option
                        v-for="item in filterOptions.cities"
                        :key="item"
                        :value="item"
                    >
                        {{ item }}
                    </option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="filter-honorary">Ehrenmitglied</Label
                ><select
                    id="filter-honorary"
                    v-model="draft.honorary"
                    :class="selectClass"
                >
                    <option value="">Alle</option>
                    <option value="yes">Ja</option>
                    <option value="no">Nein</option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="filter-email">E-Mail-Adresse</Label
                ><select
                    id="filter-email"
                    v-model="draft.email_status"
                    :class="selectClass"
                >
                    <option value="">Alle</option>
                    <option value="with">Vorhanden</option>
                    <option value="without">Fehlt</option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="filter-address">Postanschrift</Label
                ><select
                    id="filter-address"
                    v-model="draft.address_status"
                    :class="selectClass"
                >
                    <option value="">Alle</option>
                    <option value="complete">Vollständig</option>
                    <option value="incomplete">Unvollständig</option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="filter-joined-from">Eintritt von</Label
                ><Input
                    id="filter-joined-from"
                    v-model="draft.joined_from"
                    type="date"
                />
            </div>
            <div class="grid gap-2">
                <Label for="filter-joined-to">Eintritt bis</Label
                ><Input
                    id="filter-joined-to"
                    v-model="draft.joined_to"
                    type="date"
                    :min="draft.joined_from"
                />
            </div>
            <div
                v-for="field in customFilters"
                :key="field.key"
                class="grid gap-2"
            >
                <Label :for="`filter-${field.key}`">{{ field.label }}</Label
                ><select
                    v-if="field.type === 'select' || field.type === 'boolean'"
                    :id="`filter-${field.key}`"
                    v-model="draft.custom[field.key]"
                    :class="selectClass"
                >
                    <option value="">Alle</option>
                    <template v-if="field.type === 'boolean'"
                        ><option value="1">Ja</option>
                        <option value="0">Nein</option></template
                    >
                    <option
                        v-for="(label, value) in field.options"
                        v-else
                        :key="value"
                        :value="value"
                    >
                        {{ label }}
                    </option></select
                ><Input
                    v-else
                    :id="`filter-${field.key}`"
                    v-model="draft.custom[field.key]"
                    :type="
                        field.type === 'date'
                            ? 'date'
                            : ['number', 'decimal'].includes(field.type)
                              ? 'number'
                              : 'text'
                    "
                    :step="field.type === 'decimal' ? '0.01' : undefined"
                    placeholder="Alle"
                />
            </div>
        </div>
    </section>
</template>
