<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Save } from '@lucide/vue';
import ConfigurationNav from '@/components/configuration/ConfigurationNav.vue';
import CountryInput from '@/components/CountryInput.vue';
import PostalCityInput from '@/components/PostalCityInput.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { edit, update } from '@/routes/configuration/club';
import type { MemberValue } from '@/types/members';

type Field = {
    key: string;
    label: string;
    type: string;
    section: string;
    required: boolean;
};
const props = defineProps<{
    club: Record<string, MemberValue>;
    version: number;
    fields: Field[];
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Konfiguration', href: edit() },
            { title: 'Vereinsdaten' },
        ],
    },
});
function fieldId(key: string) {
    return (
        (
            {
                register_number: 'club-reference-01',
                tax_number: 'club-reference-02',
                vat_id: 'club-reference-03',
                account_holder: 'club-reference-04',
                iban: 'club-reference-05',
                bic: 'club-reference-06',
                bank_name: 'club-reference-07',
                creditor_id: 'club-reference-08',
            } as Record<string, string>
        )[key] || `club-${key}`
    );
}
function fieldAutocomplete(key: string) {
    return (
        (
            {
                name: 'section-club organization',
                street: 'section-club street-address',
                postal_code: 'section-club postal-code',
                city: 'section-club address-level2',
                email: 'section-club email',
                phone: 'section-club tel',
                website: 'section-club url',
            } as Record<string, string>
        )[key] || 'off'
    );
}
const groups = computed(() => [
    ...new Set(props.fields.map((field) => field.section)),
]);
function values(): Record<string, MemberValue> {
    return {
        ...Object.fromEntries(
            props.fields.map((field) => [
                field.key,
                props.club[field.key] ??
                    (field.type === 'boolean' ? false : null),
            ]),
        ),
        version: props.version,
    };
}
const form = useForm(values());
const logoForm = useForm<{ version: number; logo: File | null }>({
    version: props.version,
    logo: null,
});
const logoInput = ref<HTMLInputElement | null>(null);
function saveLogo() {
    if (!logoForm.logo || form.isDirty) return;
    logoForm.version = props.version;
    logoForm.post('/konfiguration/verein/logo', {
        preserveScroll: true,
        onSuccess: () => {
            logoForm.reset();
            if (logoInput.value) logoInput.value.value = '';
        },
    });
}
function removeLogo() {
    if (form.isDirty || logoForm.processing) return;
    router.delete('/konfiguration/verein/logo', {
        data: { version: props.version },
        preserveScroll: true,
    });
}
function save() {
    form.patch(update.url(), {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults(values());
            form.reset();
        },
    });
}
</script>

<template>
    <Head title="Vereinsdaten" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Konfiguration</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Stammdaten und Einstellungen für deinen Verein.
            </p>
        </div>
        <ConfigurationNav />
        <section class="rounded-xl border bg-card p-5" aria-label="Vereinslogo">
            <h2 class="font-semibold">Vereinslogo</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Das Logo erscheint oberhalb des Arbeitsbereichs in der
                Navigation. PNG, JPEG oder WebP bis 2 MB und 2.000 × 2.000
                Pixel.
            </p>
            <img
                v-if="$page.props.logoUrl"
                :src="$page.props.logoUrl"
                alt="Aktuelles Vereinslogo"
                class="mt-4 h-24 max-w-full rounded-lg border bg-white object-contain object-left p-3"
            />
            <p v-else class="mt-4 text-sm text-muted-foreground">
                Noch kein Vereinslogo hochgeladen.
            </p>
            <form
                class="mt-4 flex flex-wrap items-end gap-3"
                @submit.prevent="saveLogo"
            >
                <div class="space-y-1">
                    <Label for="club-logo">Neues Logo</Label
                    ><input
                        id="club-logo"
                        ref="logoInput"
                        type="file"
                        accept="image/png,image/jpeg,image/webp"
                        class="block max-w-xs text-sm file:mr-3 file:rounded-md file:border file:bg-background file:px-3 file:py-2"
                        @change="
                            logoForm.logo =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] ?? null
                        "
                    />
                </div>
                <Button
                    type="submit"
                    variant="outline"
                    :disabled="
                        !logoForm.logo || logoForm.processing || form.isDirty
                    "
                    data-test="save-logo"
                    >Logo hochladen</Button
                >
                <Button
                    v-if="club.logo_path"
                    type="button"
                    variant="ghost"
                    :disabled="logoForm.processing || form.isDirty"
                    @click="removeLogo"
                    >Logo entfernen</Button
                >
            </form>
            <InputError
                :message="logoForm.errors.logo || logoForm.errors.version"
            />
            <p v-if="form.isDirty" class="mt-2 text-xs text-muted-foreground">
                Bitte zuerst die geänderten Vereinsdaten speichern.
            </p>
        </section>
        <form
            class="space-y-5"
            data-test="club-form"
            autocomplete="off"
            novalidate
            @submit.prevent="save"
        >
            <InputError :message="form.errors.version" role="alert" />
            <section
                v-for="group in groups"
                :key="group"
                class="rounded-xl border bg-card"
            >
                <h2 class="border-b px-5 py-4 text-sm font-semibold">
                    {{ group }}
                </h2>
                <div class="grid gap-5 p-5 sm:grid-cols-2">
                    <div
                        v-for="field in fields.filter(
                            (field) => field.section === group,
                        )"
                        :key="field.key"
                        class="space-y-2"
                    >
                        <Label :for="fieldId(field.key)"
                            >{{ field.label
                            }}{{ field.required ? ' *' : '' }}</Label
                        >
                        <CountryInput
                            v-if="field.type === 'country'"
                            :id="fieldId(field.key)"
                            :name="fieldId(field.key)"
                            :model-value="String(form[field.key] || '')"
                            :disabled="form.processing"
                            @update:model-value="form[field.key] = $event"
                        />
                        <PostalCityInput
                            v-else-if="field.key === 'city'"
                            :id="fieldId(field.key)"
                            :name="fieldId(field.key)"
                            :autocomplete="fieldAutocomplete(field.key)"
                            :model-value="String(form[field.key] || '')"
                            :postal-code="String(form.postal_code || '')"
                            :country="String(form.country || 'DE')"
                            :disabled="form.processing"
                            @update:model-value="form[field.key] = $event"
                        />
                        <select
                            v-else-if="field.type === 'boolean'"
                            :id="fieldId(field.key)"
                            class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                            :value="String(form[field.key])"
                            :disabled="form.processing"
                            @change="
                                form[field.key] =
                                    ($event.target as HTMLSelectElement)
                                        .value === 'true'
                            "
                        >
                            <option value="false">Nein</option>
                            <option value="true">Ja</option>
                        </select>
                        <Input
                            v-else
                            :id="fieldId(field.key)"
                            :model-value="String(form[field.key] ?? '')"
                            :type="field.type"
                            :name="fieldId(field.key)"
                            :autocomplete="fieldAutocomplete(field.key)"
                            data-lpignore="true"
                            data-1p-ignore
                            maxlength="255"
                            :required="field.required"
                            :disabled="form.processing"
                            :aria-invalid="!!form.errors[field.key]"
                            @update:model-value="
                                form[field.key] = $event === '' ? null : $event
                            "
                        />
                        <InputError :message="form.errors[field.key]" />
                    </div>
                </div>
            </section>
            <div
                class="sticky bottom-0 flex justify-end border-t bg-background/95 py-3 backdrop-blur"
            >
                <Button :disabled="form.processing" data-test="save-club"
                    ><Spinner v-if="form.processing" /><Save
                        v-else
                        class="size-4"
                    />Vereinsdaten speichern</Button
                >
            </div>
        </form>
    </div>
</template>
