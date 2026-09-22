<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import { Input } from '@/components/ui/input';
import { lookup } from '@/routes/postal';
defineOptions({ inheritAttrs: false });

const props = defineProps<{
    id: string;
    modelValue: string | null;
    postalCode: string | null;
    country: string;
    disabled?: boolean;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();
const cities = ref<string[]>([]);
const message = ref('');
const busy = ref(false);
const initialPostal = props.postalCode;
let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;
let sequence = 0;
function cancel() {
    clearTimeout(timer);
    controller?.abort();
    sequence++;
}
watch(
    () => [props.postalCode, props.country],
    () => {
        cancel();
        cities.value = [];
        message.value = '';
        busy.value = false;
        if (!props.postalCode || !props.country) return;
        const requestSequence = sequence;
        timer = setTimeout(async () => {
            controller = new AbortController();
            busy.value = true;
            const previousCity = props.modelValue;
            try {
                const response = await fetch(
                    lookup.url({
                        query: {
                            country: props.country,
                            postal_code: props.postalCode ?? '',
                        },
                    }),
                    {
                        headers: { Accept: 'application/json' },
                        signal: controller.signal,
                    },
                );
                if (!response.ok) throw new Error('Lookup unavailable');
                const data: { cities: string[]; supported: boolean } =
                    await response.json();
                if (requestSequence !== sequence) return;
                cities.value = data.cities;
                if (
                    data.cities.length === 1 &&
                    props.modelValue === previousCity &&
                    (!props.modelValue || props.postalCode !== initialPostal)
                )
                    emit('update:modelValue', data.cities[0]);
                if (!data.supported)
                    message.value =
                        'Für dieses Land bitte den Ort manuell eingeben.';
                else if (!data.cities.length)
                    message.value =
                        'Keine Ortszuordnung gefunden. Bitte den Ort manuell eingeben.';
            } catch (error) {
                if (
                    requestSequence === sequence &&
                    !(
                        error instanceof DOMException &&
                        error.name === 'AbortError'
                    )
                )
                    message.value =
                        'Ortsvorschläge gerade nicht verfügbar. Die manuelle Eingabe ist weiterhin möglich.';
            } finally {
                if (requestSequence === sequence) busy.value = false;
            }
        }, 300);
    },
    { immediate: true },
);
onBeforeUnmount(cancel);
</script>

<template>
    <div class="space-y-2">
        <Input
            v-bind="$attrs"
            :id="id"
            :model-value="modelValue ?? ''"
            :list="`${id}-cities`"
            :disabled="disabled"
            :aria-busy="busy"
            @update:model-value="
                emit('update:modelValue', String($event) || null)
            "
        />
        <datalist :id="`${id}-cities`">
            <option v-for="city in cities" :key="city" :value="city" />
        </datalist>
        <select
            v-if="cities.length > 1"
            class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
            :aria-label="`Ort zur PLZ ${postalCode} auswählen`"
            :disabled="disabled"
            value=""
            @change="
                emit(
                    'update:modelValue',
                    ($event.target as HTMLSelectElement).value,
                )
            "
        >
            <option value="" disabled>
                {{ `${cities.length} Orte zur PLZ – bitte auswählen` }}
            </option>
            <option v-for="city in cities" :key="city" :value="city">
                {{ city }}
            </option>
        </select>
        <p v-if="message" role="status" class="text-xs text-muted-foreground">
            {{ message }}
        </p>
        <p v-if="cities.length" class="text-xs text-muted-foreground">
            Ortsvorschläge:
            <a
                href="https://www.geonames.org/"
                target="_blank"
                rel="noopener noreferrer"
                class="underline underline-offset-2"
                >GeoNames</a
            >
        </p>
    </div>
</template>
