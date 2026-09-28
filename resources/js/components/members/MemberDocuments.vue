<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { Download, FileText, Upload } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { MemberDocument } from '@/types/members';
type DocumentKind = 'application' | 'sepa';

const props = defineProps<{
    documents: MemberDocument[];
    editing: boolean;
    /** The page's upload forms; the page tracks unsaved changes. */
    uploads: Record<DocumentKind, InertiaForm<{ document: File | null }>>;
}>();
const emit = defineEmits<{
    choose: [kind: DocumentKind, event: Event];
    upload: [kind: DocumentKind];
}>();

const documentKinds: Array<{ kind: DocumentKind; label: string }> = [
    { kind: 'application', label: 'Mitgliedsantrag' },
];
function documentFor(kind: DocumentKind) {
    return props.documents.find((document) => document.kind === kind);
}
</script>

<template>
    <section
        class="rounded-xl border bg-card"
        aria-labelledby="documents-title"
    >
        <h2
            id="documents-title"
            class="border-b px-5 py-4 text-sm font-semibold"
        >
            Dokumente
        </h2>
        <div class="divide-y px-5">
            <div
                v-for="document in documentKinds"
                :key="document.kind"
                class="flex flex-wrap items-center justify-between gap-3 py-4"
            >
                <div class="flex items-center gap-3">
                    <FileText
                        class="size-5 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <div>
                        <p class="text-sm font-medium">
                            {{ document.label }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{
                                documentFor(document.kind)
                                    ? documentFor(document.kind)
                                          ?.submitted_online
                                        ? 'Online eingereicht'
                                        : 'Hinterlegt'
                                    : 'Kein Dokument hinterlegt'
                            }}
                        </p>
                    </div>
                </div>
                <Button
                    v-if="documentFor(document.kind)"
                    as-child
                    variant="outline"
                    size="sm"
                    ><a
                        :href="documentFor(document.kind)?.url"
                        target="_blank"
                        rel="noopener"
                        ><Download class="size-4" />PDF herunterladen</a
                    ></Button
                >
                <div
                    v-if="editing"
                    class="basis-full space-y-2 rounded-lg border bg-muted/30 p-3"
                >
                    <Label :for="`document-${document.kind}`" class="text-xs">{{
                        documentFor(document.kind)
                            ? `${document.label} ersetzen`
                            : `${document.label} hochladen`
                    }}</Label>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <Input
                            :id="`document-${document.kind}`"
                            type="file"
                            accept="application/pdf,.pdf"
                            :disabled="uploads[document.kind].processing"
                            :aria-invalid="
                                !!uploads[document.kind].errors.document
                            "
                            :aria-describedby="`error-document-${document.kind}`"
                            @change="emit('choose', document.kind, $event)"
                        />
                        <Button
                            type="button"
                            variant="secondary"
                            :disabled="
                                !uploads[document.kind].document ||
                                uploads[document.kind].processing
                            "
                            @click="emit('upload', document.kind)"
                            ><Spinner
                                v-if="uploads[document.kind].processing"
                            /><Upload v-else class="size-4" />{{
                                documentFor(document.kind)
                                    ? 'Ersetzen'
                                    : 'Hochladen'
                            }}</Button
                        >
                    </div>
                    <p class="text-xs text-muted-foreground">
                        PDF, maximal 10 MB
                    </p>
                    <InputError
                        :id="`error-document-${document.kind}`"
                        :message="uploads[document.kind].errors.document"
                    />
                </div>
            </div>
        </div>
    </section>
</template>
