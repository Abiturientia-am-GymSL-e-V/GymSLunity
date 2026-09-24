<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { FileText, Save, X } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, onMounted } from 'vue';
import InputError from '@/components/InputError.vue';
import MemberFieldControl from '@/components/members/MemberFieldControl.vue';
import MembersNav from '@/components/members/MembersNav.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { create, index, store } from '@/routes/members';
import type { MemberSection, MemberValue } from '@/types/members';

const props = defineProps<{
    sections: MemberSection[];
    configurationVersion: number;
    suggestedMemberNumber: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Mitglieder', href: index() },
            { title: 'Mitglied anlegen', href: create() },
        ],
    },
});

const fields = computed(() =>
    props.sections.flatMap((section) => section.fields),
);
const initialValues = Object.fromEntries(
    fields.value.map((field) => [
        field.key,
        field.type === 'boolean' && (field.required || !field.custom)
            ? false
            : field.required && field.type === 'select'
              ? (Object.keys(field.activeOptions)[0] ?? null)
              : null,
    ]),
) as Record<string, MemberValue>;
type CreateMemberForm = Record<string, MemberValue | File> & {
    member_number: number | string;
    configuration_version: number;
    application_file: File | null;
    sepa_file: File | null;
};
const form = useForm<CreateMemberForm>({
    member_number: props.suggestedMemberNumber as number | string,
    ...initialValues,
    configuration_version: props.configurationVersion,
    application_file: null,
    sepa_file: null,
});
const fieldValues = computed(() => form.data() as Record<string, MemberValue>);

function chooseFile(kind: 'application_file' | 'sepa_file', event: Event) {
    form[kind] = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function cancel() {
    if (
        !form.isDirty ||
        window.confirm('Eingaben verwerfen und zum Verzeichnis zurückkehren?')
    ) {
        router.get(index.url());
    }
}

function submit() {
    form.post(store.url(), {
        onError: async () => {
            await nextTick();
            document
                .querySelector<HTMLElement>('[aria-invalid="true"]')
                ?.focus();
        },
    });
}

function beforeUnload(event: BeforeUnloadEvent) {
    if (form.isDirty || form.processing) {
        event.preventDefault();
        event.returnValue = '';
    }
}
onMounted(() => window.addEventListener('beforeunload', beforeUnload));
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload));
</script>

<template>
    <Head title="Mitglied anlegen" />
    <form
        class="mx-auto flex w-full max-w-[1200px] flex-col gap-6 p-4 sm:p-6"
        novalidate
        data-test="create-member"
        @submit.prevent="submit"
    >
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Mitglieder</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Erfasse die Stammdaten und Dokumente eines neuen Mitglieds.
            </p>
        </header>

        <MembersNav />

        <div
            v-if="form.errors.form"
            role="alert"
            class="rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive"
        >
            {{ form.errors.form }}
        </div>

        <section
            class="rounded-xl border bg-card"
            aria-labelledby="member-number-title"
        >
            <div class="border-b px-5 py-4">
                <h2 id="member-number-title" class="text-sm font-semibold">
                    Zuordnung
                </h2>
            </div>
            <div class="max-w-md space-y-2 p-5">
                <Label for="member-member_number">Mitgliedsnummer *</Label>
                <Input
                    id="member-member_number"
                    v-model="form.member_number"
                    name="member_number"
                    type="number"
                    min="1"
                    required
                    :disabled="form.processing"
                    :aria-invalid="!!form.errors.member_number"
                    aria-describedby="error-member_number"
                />
                <InputError
                    id="error-member_number"
                    :message="form.errors.member_number"
                />
            </div>
        </section>

        <section
            v-for="(section, sectionIndex) in sections"
            :key="section.key"
            class="rounded-xl border bg-card"
            :aria-labelledby="`create-section-${sectionIndex}`"
        >
            <div class="border-b px-5 py-4">
                <h2
                    :id="`create-section-${sectionIndex}`"
                    class="text-sm font-semibold"
                >
                    {{ section.title }}
                </h2>
            </div>
            <div class="grid gap-x-6 gap-y-5 p-5 md:grid-cols-2">
                <div
                    v-for="field in section.fields"
                    :key="field.key"
                    class="min-w-0 space-y-2"
                >
                    <Label
                        :for="`member-${field.key}`"
                        class="text-xs text-muted-foreground"
                    >
                        {{ field.label
                        }}<span v-if="field.required" aria-hidden="true">
                            *</span
                        >
                    </Label>
                    <MemberFieldControl
                        :field="field"
                        :value="form[field.key] as MemberValue"
                        :values="fieldValues"
                        :disabled="form.processing"
                        :error="form.errors[field.key]"
                        @change="form[field.key] = $event"
                    />
                    <InputError
                        :id="`error-${field.key}`"
                        :message="form.errors[field.key]"
                    />
                </div>
            </div>
        </section>

        <section
            class="rounded-xl border bg-card"
            aria-labelledby="create-documents-title"
        >
            <div class="border-b px-5 py-4">
                <h2 id="create-documents-title" class="text-sm font-semibold">
                    Dokumente
                </h2>
            </div>
            <div class="grid gap-5 p-5 md:grid-cols-2">
                <div
                    v-for="document in [
                        {
                            key: 'application_file' as const,
                            label: 'Schriftlicher Antrag',
                        },
                        { key: 'sepa_file' as const, label: 'SEPA-Mandat' },
                    ]"
                    :key="document.key"
                    class="space-y-2"
                >
                    <Label
                        :for="`member-${document.key}`"
                        class="flex items-center gap-2"
                    >
                        <FileText class="size-4" aria-hidden="true" />{{
                            document.label
                        }}
                    </Label>
                    <Input
                        :id="`member-${document.key}`"
                        :name="document.key"
                        type="file"
                        accept="application/pdf,.pdf"
                        :disabled="form.processing"
                        :aria-invalid="!!form.errors[document.key]"
                        :aria-describedby="`error-${document.key}`"
                        @change="chooseFile(document.key, $event)"
                    />
                    <p class="text-xs text-muted-foreground">
                        PDF, maximal 10 MB
                    </p>
                    <InputError
                        :id="`error-${document.key}`"
                        :message="form.errors[document.key]"
                    />
                </div>
            </div>
        </section>

        <footer
            class="sticky bottom-0 z-20 -mx-4 -mb-4 flex items-center justify-between gap-2 border-t bg-background/95 px-4 py-3 backdrop-blur sm:-mx-6 sm:-mb-6 sm:px-6"
        >
            <span class="text-xs text-muted-foreground">* Pflichtfelder</span>
            <div class="flex gap-2">
                <Button
                    type="button"
                    variant="outline"
                    :disabled="form.processing"
                    @click="cancel"
                >
                    <X class="size-4" />Abbrechen
                </Button>
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" /><Save
                        v-else
                        class="size-4"
                    />Mitglied anlegen
                </Button>
            </div>
        </footer>
    </form>
</template>
