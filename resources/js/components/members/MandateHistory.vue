<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { Download, Upload } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { memberTimestamp } from '@/lib/memberFormatting';
import type { MemberMandate } from '@/types/members';
defineProps<{
    mandates: MemberMandate[];
    editing: boolean;
    /** The page's SEPA upload form; the page tracks unsaved changes. */
    upload: InertiaForm<{ document: File | null }>;
}>();
const emit = defineEmits<{ choose: [event: Event]; upload: [] }>();

function dateOnly(value: string | null) {
    return value
        ? value.slice(0, 10).split('-').reverse().join('.')
        : 'Nicht hinterlegt';
}
</script>

<template>
    <section class="rounded-xl border bg-card" aria-labelledby="mandates-title">
        <div class="border-b px-5 py-4">
            <h2 id="mandates-title" class="text-sm font-semibold">
                SEPA-Mandatshistorie
            </h2>
            <p class="mt-1 text-xs text-muted-foreground">
                Aktuelle und widerrufene Mandate bleiben vollständig
                nachvollziehbar.
            </p>
        </div>
        <div v-if="mandates.length" class="divide-y px-5">
            <div
                v-for="mandate in mandates"
                :key="mandate.id"
                class="flex flex-wrap items-start justify-between gap-4 py-4"
            >
                <div class="min-w-0 space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-sm font-medium">
                            {{
                                mandate.mandate_reference ||
                                `Mandat #${mandate.id}`
                            }}
                        </p>
                        <Badge
                            :variant="mandate.active ? 'default' : 'secondary'"
                        >
                            {{
                                mandate.active
                                    ? 'Aktiv'
                                    : 'Widerrufen / archiviert'
                            }}
                        </Badge>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Unterzeichnet:
                        {{ dateOnly(mandate.mandate_signed_at) }} ·
                        {{
                            mandate.submitted_online
                                ? 'Online eingereicht'
                                : 'Manuell hinterlegt'
                        }}
                    </p>
                    <p
                        v-if="mandate.revoked_at"
                        class="text-xs text-muted-foreground"
                    >
                        Widerrufen:
                        {{ memberTimestamp(mandate.revoked_at) }}
                        <template v-if="mandate.revocation_reason">
                            · {{ mandate.revocation_reason }}
                        </template>
                    </p>
                </div>
                <Button as-child variant="outline" size="sm">
                    <a :href="mandate.url" target="_blank" rel="noopener"
                        ><Download class="size-4" />PDF herunterladen</a
                    >
                </Button>
            </div>
        </div>
        <p v-else class="px-5 py-4 text-sm text-muted-foreground">
            Noch kein SEPA-Mandat hinterlegt.
        </p>
        <div
            v-if="editing"
            class="m-5 space-y-2 rounded-lg border bg-muted/30 p-3"
        >
            <Label for="document-sepa" class="text-xs">
                Neues SEPA-Mandat zur Historie hinzufügen
            </Label>
            <div class="flex flex-col gap-2 sm:flex-row">
                <Input
                    id="document-sepa"
                    type="file"
                    accept="application/pdf,.pdf"
                    :disabled="upload.processing"
                    :aria-invalid="!!upload.errors.document"
                    aria-describedby="error-document-sepa"
                    @change="emit('choose', $event)"
                />
                <Button
                    type="button"
                    variant="secondary"
                    :disabled="!upload.document || upload.processing"
                    @click="emit('upload')"
                >
                    <Spinner v-if="upload.processing" />
                    <Upload v-else class="size-4" />Hochladen
                </Button>
            </div>
            <p class="text-xs text-muted-foreground">
                PDF, maximal 10 MB. Ein bisher aktives Dokument wird archiviert.
            </p>
            <InputError
                id="error-document-sepa"
                :message="upload.errors.document"
            />
        </div>
    </section>
</template>
