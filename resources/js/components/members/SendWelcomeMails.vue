<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { MailPlus } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { welcomeMail } from '@/routes/members';

const props = withDefaults(
    defineProps<{
        selected: number[];
        available: boolean;
        /** Single member view: sending again is the expected action. */
        single?: boolean;
        label?: string;
    }>(),
    { single: false, label: 'Willkommensmail senden' },
);
const emit = defineEmits<{ sent: [] }>();

const limit = 500;
const open = ref(false);
const form = useForm({
    members: [] as number[],
    resend: false,
    request_id: '',
});
const error = computed(() => {
    const errors = form.errors as Record<string, string>;
    return (
        errors.members ||
        errors.resend ||
        errors.request_id ||
        Object.entries(errors).find(([key]) => key.startsWith('members.'))?.[1]
    );
});

function show() {
    form.reset();
    form.clearErrors();
    form.resend = props.single;
    open.value = true;
}
function close(value: boolean) {
    if (!value && !form.processing) open.value = false;
}
function submit() {
    form.members = [...props.selected];
    form.request_id = crypto.randomUUID();
    form.post(welcomeMail.url(), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            emit('sent');
        },
    });
}
</script>

<template>
    <Button
        v-if="selected.length"
        type="button"
        size="sm"
        variant="outline"
        data-test="send-welcome-mails"
        @click="show"
    >
        <MailPlus class="size-4" />{{ label }}
    </Button>
    <Dialog :open="open" @update:open="close">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{
                    single
                        ? 'Willkommensmail senden'
                        : `Willkommensmail an ${selected.length} ${selected.length === 1 ? 'Mitglied' : 'Mitglieder'}`
                }}</DialogTitle>
                <DialogDescription>
                    Die E-Mail erklärt die Anmeldung im Mitgliederbereich. Sie
                    geht an die aktuell hinterlegte Adresse; Mitglieder ohne
                    gültige Adresse werden übersprungen. Den Text
                    {{ $address('kannst du', 'können Sie') }} unter
                    Konfiguration → Startseite anpassen.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-5">
                <StatusAlert
                    v-if="!available"
                    type="warning"
                    title="Selfservice deaktiviert"
                >
                    Willkommensmails setzen den aktivierten
                    Mitglieder-Selfservice voraus.
                </StatusAlert>
                <StatusAlert
                    v-else-if="selected.length > limit"
                    type="warning"
                    title="Auswahl zu groß"
                >
                    Pro Versand sind höchstens {{ limit }} Mitglieder möglich.
                </StatusAlert>
                <label
                    v-if="!single"
                    class="flex items-start gap-3 text-sm"
                    for="welcome-resend"
                >
                    <Checkbox id="welcome-resend" v-model="form.resend" />
                    <span class="space-y-1">
                        <span class="block font-medium"
                            >Auch an Mitglieder senden, die bereits eine
                            Willkommensmail erhalten haben</span
                        >
                        <span class="block text-muted-foreground"
                            >Ohne diese Option werden sie übersprungen. Über den
                            Filter „Willkommensmail: noch nicht erhalten“
                            {{ $address('kannst du', 'können Sie') }} gezielt
                            nur die übrigen auswählen.</span
                        >
                    </span>
                </label>
                <InputError :message="error" />
            </div>

            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="form.processing"
                    @click="open = false"
                    >Abbrechen</Button
                >
                <Button
                    type="button"
                    :disabled="
                        form.processing || !available || selected.length > limit
                    "
                    data-test="submit-welcome-mails"
                    @click="submit"
                >
                    <Spinner v-if="form.processing" />
                    Jetzt senden
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
