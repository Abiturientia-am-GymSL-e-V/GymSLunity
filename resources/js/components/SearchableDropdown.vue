<script setup lang="ts">
import { Check, ChevronDown } from '@lucide/vue';
import { onClickOutside, useEventListener } from '@vueuse/core';
import { computed, nextTick, ref, watch } from 'vue';

defineOptions({ inheritAttrs: false });

type DropdownOption = {
    value: string;
    label: string;
    icon?: string;
    suffix?: string;
    search?: string;
};

const props = withDefaults(
    defineProps<{
        id: string;
        modelValue: string | null;
        options: DropdownOption[];
        disabled?: boolean;
        placeholder?: string;
        searchPlaceholder?: string;
        emptyText?: string;
        ariaLabel?: string;
        rootClass?: string;
        triggerClass?: string;
        dropdownClass?: string;
    }>(),
    {
        disabled: false,
        placeholder: 'Bitte auswählen',
        searchPlaceholder: 'Suchen',
        emptyText: 'Kein Treffer',
        ariaLabel: undefined,
        rootClass: '',
        triggerClass: '',
        dropdownClass: '',
    },
);
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const root = ref<HTMLElement>();
const trigger = ref<HTMLButtonElement>();
const panel = ref<HTMLElement>();
const searchInput = ref<HTMLInputElement>();
const listbox = ref<HTMLElement>();
const open = ref(false);
const positioned = ref(false);
const search = ref('');
const active = ref(-1);
const panelStyle = ref<Record<string, string>>({});
const listMaxHeight = ref('14rem');
const portalTarget = ref<string | HTMLElement>('body');

const selected = computed(() =>
    props.options.find((option) => option.value === props.modelValue),
);
const filtered = computed(() => {
    const term = normalize(search.value);
    return props.options.filter((option) => {
        const haystack =
            option.search ??
            [option.label, option.value, option.suffix]
                .filter(Boolean)
                .join(' ');
        return !term || normalize(haystack).includes(term);
    });
});

function normalize(value: string) {
    return value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLocaleLowerCase('de')
        .trim();
}

function close(focusTrigger = false) {
    open.value = false;
    positioned.value = false;
    search.value = '';
    active.value = -1;
    panelStyle.value = {};
    if (focusTrigger)
        void nextTick(() => trigger.value?.focus({ preventScroll: true }));
}

function positionPanel() {
    if (!open.value || !trigger.value || typeof window === 'undefined') return;

    const rect = trigger.value.getBoundingClientRect();
    const container =
        portalTarget.value instanceof HTMLElement ? portalTarget.value : null;
    const containerRect = container?.getBoundingClientRect();
    const margin = 8;
    const gap = 4;
    const viewportWidth = window.innerWidth;
    const viewportHeight = window.innerHeight;
    const boundaryLeft = Math.max(margin, (containerRect?.left ?? 0) + margin);
    const boundaryRight = Math.min(
        viewportWidth - margin,
        (containerRect?.right ?? viewportWidth) - margin,
    );
    const boundaryTop = Math.max(margin, (containerRect?.top ?? 0) + margin);
    const boundaryBottom = Math.min(
        viewportHeight - margin,
        (containerRect?.bottom ?? viewportHeight) - margin,
    );
    const width = Math.min(
        Math.max(rect.width, 256),
        Math.max(0, boundaryRight - boundaryLeft),
    );
    const left = Math.min(
        Math.max(rect.left, boundaryLeft),
        Math.max(boundaryLeft, boundaryRight - width),
    );
    const availableBelow = boundaryBottom - rect.bottom - gap;
    const availableAbove = rect.top - gap - boundaryTop;
    const preferredHeight = Math.min(panel.value?.scrollHeight || 284, 284);
    const openAbove =
        availableBelow < preferredHeight && availableAbove > availableBelow;
    const availableHeight = Math.max(
        0,
        openAbove ? availableAbove : availableBelow,
    );

    listMaxHeight.value = `${Math.max(
        64,
        Math.min(224, availableHeight - 60),
    )}px`;
    panelStyle.value = {
        left: `${Math.round(left - (containerRect?.left ?? 0))}px`,
        width: `${Math.round(width)}px`,
        top: `${Math.round(
            (openAbove ? rect.top - gap - preferredHeight : rect.bottom + gap) -
                (containerRect?.top ?? 0),
        )}px`,
    };
}

function scrollActiveOption() {
    if (active.value < 0 || !listbox.value) return;

    const option = document.getElementById(
        `${props.id}-option-${active.value}`,
    );
    if (!option) return;

    const listRect = listbox.value.getBoundingClientRect();
    const optionRect = option.getBoundingClientRect();
    if (optionRect.top < listRect.top) {
        listbox.value.scrollTop -= listRect.top - optionRect.top;
    } else if (optionRect.bottom > listRect.bottom) {
        listbox.value.scrollTop += optionRect.bottom - listRect.bottom;
    }
}

function toggle() {
    if (props.disabled) return;
    if (open.value) {
        close();
        return;
    }
    search.value = '';
    positioned.value = false;
    portalTarget.value =
        trigger.value?.closest<HTMLElement>('[data-slot="dialog-content"]') ??
        'body';
    open.value = true;
    active.value = Math.max(
        0,
        props.options.findIndex((option) => option.value === props.modelValue),
    );
    void nextTick(async () => {
        positionPanel();
        positioned.value = true;
        // Apply the fixed coordinates before focusing the teleported input.
        // Otherwise browsers scroll to its temporary position at the end of
        // the document, which is especially visible on touch devices.
        await nextTick();
        searchInput.value?.focus({ preventScroll: true });
        scrollActiveOption();
        window.requestAnimationFrame(positionPanel);
    });
}

function choose(value: string) {
    emit('update:modelValue', value);
    close(true);
}

function move(step: number) {
    if (!filtered.value.length) return;
    active.value =
        (active.value + step + filtered.value.length) % filtered.value.length;
    void nextTick(scrollActiveOption);
}

function keyboard(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        event.preventDefault();
        close(true);
    } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        move(event.key === 'ArrowDown' ? 1 : -1);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        const option =
            filtered.value[active.value] ??
            (filtered.value.length === 1 ? filtered.value[0] : undefined);
        if (option) choose(option.value);
    }
}

function closeOnExternalFocus(event: FocusEvent) {
    const next = event.target;
    if (
        !open.value ||
        !(next instanceof Node) ||
        root.value?.contains(next) ||
        panel.value?.contains(next)
    )
        return;
    close();
}

onClickOutside(root, () => close(), { ignore: [panel] });
// Listen for a confirmed focus target instead of relying on focusout's
// relatedTarget, which is unreliable in all WebKit-based iPad browsers.
useEventListener('focusin', closeOnExternalFocus);
useEventListener('resize', positionPanel);
useEventListener('scroll', positionPanel, { capture: true, passive: true });
watch(
    () => props.disabled,
    (disabled) => {
        if (disabled) close();
    },
);
watch(search, () => {
    active.value = filtered.value.length ? 0 : -1;
    void nextTick(() => {
        positionPanel();
        scrollActiveOption();
    });
});
watch(
    () => props.options,
    () => {
        if (open.value) void nextTick(positionPanel);
    },
);
</script>

<template>
    <div ref="root" class="relative" :class="rootClass">
        <button
            v-bind="$attrs"
            :id="id"
            ref="trigger"
            type="button"
            role="combobox"
            aria-haspopup="listbox"
            :aria-label="ariaLabel"
            :aria-expanded="open"
            :aria-controls="`${id}-listbox`"
            :disabled="disabled"
            class="flex items-center gap-2 text-left text-base disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
            :class="triggerClass"
            @click="toggle"
            @keydown.down.prevent="if (!open) toggle();"
            @keydown.up.prevent="if (!open) toggle();"
            @keydown.esc.prevent="close()"
        >
            <slot name="trigger" :option="selected">
                <span v-if="selected?.icon" aria-hidden="true">{{
                    selected.icon
                }}</span>
                <span class="min-w-0 flex-1 truncate">{{
                    selected?.label ?? placeholder
                }}</span>
            </slot>
            <ChevronDown
                class="ml-auto size-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
        </button>

        <Teleport :to="portalTarget">
            <div
                v-if="open"
                ref="panel"
                class="pointer-events-auto fixed z-[100] rounded-md border bg-popover p-2 text-popover-foreground shadow-md"
                :class="[dropdownClass, { invisible: !positioned }]"
                :style="panelStyle"
            >
                <input
                    :id="`${id}-search`"
                    ref="searchInput"
                    v-model="search"
                    type="search"
                    autocomplete="off"
                    :placeholder="searchPlaceholder"
                    class="mb-2 h-9 w-full rounded border bg-background px-3 text-sm"
                    role="combobox"
                    aria-autocomplete="list"
                    :aria-expanded="open"
                    :aria-controls="`${id}-listbox`"
                    :aria-activedescendant="
                        active >= 0 ? `${id}-option-${active}` : undefined
                    "
                    @keydown="keyboard"
                />
                <div
                    :id="`${id}-listbox`"
                    ref="listbox"
                    role="listbox"
                    :aria-label="ariaLabel"
                    class="overflow-y-auto"
                    :style="{ maxHeight: listMaxHeight }"
                >
                    <button
                        v-for="(option, index) in filtered"
                        :id="`${id}-option-${index}`"
                        :key="option.value"
                        type="button"
                        role="option"
                        :aria-selected="option.value === modelValue"
                        class="flex w-full items-center gap-2 rounded px-2 py-2 text-left text-sm hover:bg-accent"
                        :class="{ 'bg-accent': active === index }"
                        @mouseenter="active = index"
                        @click="choose(option.value)"
                    >
                        <slot name="option" :option="option">
                            <span v-if="option.icon" aria-hidden="true">{{
                                option.icon
                            }}</span>
                            <span class="min-w-0 flex-1 truncate">{{
                                option.label
                            }}</span>
                            <span
                                v-if="option.suffix"
                                class="shrink-0 text-muted-foreground"
                                >{{ option.suffix }}</span
                            >
                        </slot>
                        <Check
                            v-if="option.value === modelValue"
                            class="size-4 shrink-0"
                            aria-hidden="true"
                        />
                    </button>
                    <p
                        v-if="!filtered.length"
                        role="status"
                        class="px-2 py-2 text-sm text-muted-foreground"
                    >
                        {{ emptyText }}
                    </p>
                </div>
            </div>
        </Teleport>
    </div>
</template>
