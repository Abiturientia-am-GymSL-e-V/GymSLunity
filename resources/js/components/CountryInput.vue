<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { ChevronDown, Check } from '@lucide/vue';
import countries from '../../data/countries.json';
import { Input } from '@/components/ui/input';

defineOptions({ inheritAttrs: false });
const props = defineProps<{
    id: string;
    modelValue: string | null;
    disabled?: boolean;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();
const options: Record<string, string> = countries;
const entries = Object.entries(options).sort((a, b) =>
    a[1].localeCompare(b[1], 'de'),
);
const open = ref(false);
const showAll = ref(false);
const active = ref(-1);
const display = ref(options[props.modelValue ?? ''] ?? props.modelValue ?? '');
const filtered = computed(() => {
    const text = showAll.value
        ? ''
        : display.value.trim().toLocaleLowerCase('de');
    return entries.filter(
        ([code, label]) =>
            code.toLowerCase().includes(text) ||
            label.toLocaleLowerCase('de').includes(text),
    );
});
watch(
    () => props.modelValue,
    (value) => {
        if (!open.value) display.value = options[value ?? ''] ?? value ?? '';
    },
);
function change(value: string | number) {
    display.value = String(value);
    showAll.value = false;
    active.value = -1;
    open.value = true;
    emit('update:modelValue', display.value || null);
}
function choose(code: string) {
    display.value = options[code];
    open.value = false;
    emit('update:modelValue', code);
}
function normalize() {
    open.value = false;
    const text = display.value.trim();
    const found = entries.find(
        ([code, label]) =>
            code.toLowerCase() === text.toLowerCase() ||
            label.toLowerCase() === text.toLowerCase(),
    );
    emit('update:modelValue', found?.[0] ?? (text || null));
    display.value = found?.[1] ?? text;
}
function expand() {
    document.getElementById(props.id)?.focus();
    showAll.value = true;
    open.value = true;
    active.value = -1;
}
function keyboard(event: KeyboardEvent) {
    if (event.key === 'Escape' && open.value) {
        event.preventDefault();
        event.stopPropagation();
        open.value = false;
    } else if (['ArrowDown', 'ArrowUp'].includes(event.key)) {
        event.preventDefault();
        if (!open.value) {
            open.value = true;
            showAll.value = true;
        }
        active.value = Math.max(
            0,
            Math.min(
                filtered.value.length - 1,
                active.value + (event.key === 'ArrowDown' ? 1 : -1),
            ),
        );
        void nextTick(() =>
            document
                .getElementById(`${props.id}-option-${active.value}`)
                ?.scrollIntoView({ block: 'nearest' }),
        );
    } else if (event.key === 'Enter' && open.value) {
        event.preventDefault();
        const entry = filtered.value[active.value];
        if (entry) choose(entry[0]);
        else normalize();
    }
}
</script>

<template>
    <div class="relative">
        <Input
            v-bind="$attrs"
            :id="id"
            :model-value="display"
            :disabled="disabled"
            class="pr-9"
            autocomplete="off"
            role="combobox"
            aria-autocomplete="list"
            :aria-expanded="open"
            :aria-controls="`${id}-countries`"
            :aria-activedescendant="
                open && active >= 0 ? `${id}-option-${active}` : undefined
            "
            placeholder="Land eingeben oder auswählen"
            @focus="
                open = true;
                showAll = true;
                active = -1;
            "
            @blur="normalize"
            @keydown="keyboard"
            @update:model-value="change"
        />
        <button
            type="button"
            tabindex="-1"
            class="absolute inset-y-0 right-0 px-2 text-muted-foreground"
            aria-label="Länderliste öffnen"
            :disabled="disabled"
            @mousedown.prevent
            @click="expand"
        >
            <ChevronDown class="size-4" />
        </button>
        <div
            v-if="open"
            :id="`${id}-countries`"
            role="listbox"
            aria-label="Länder"
            class="absolute z-50 mt-1 max-h-56 w-full overflow-y-auto rounded-md border bg-popover p-1 text-popover-foreground shadow-md"
        >
            <div
                v-for="([code, label], index) in filtered"
                :id="`${id}-option-${index}`"
                :key="code"
                role="option"
                :aria-selected="modelValue === code"
                class="flex cursor-pointer items-center justify-between gap-2 rounded-sm px-3 py-2 text-sm hover:bg-accent"
                :class="{ 'bg-accent': active === index }"
                @mousedown.prevent
                @click="choose(code)"
            >
                <span
                    >{{ label }}
                    <span class="text-xs text-muted-foreground">{{
                        code
                    }}</span></span
                ><Check v-if="modelValue === code" class="size-4" />
            </div>
            <p
                v-if="!filtered.length"
                role="status"
                class="px-3 py-2 text-sm text-muted-foreground"
            >
                Kein Treffer. Deine Eingabe kann als Freitext gespeichert
                werden.
            </p>
        </div>
    </div>
</template>
