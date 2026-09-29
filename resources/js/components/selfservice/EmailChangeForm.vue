<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

withDefaults(defineProps<{ primary?: boolean }>(), { primary: false });

const email = useForm({ email: '', purpose: 'email' });
const emailSent = ref(false);

function sendEmailConfirmation() {
    emailSent.value = false;
    email.post('/selfservice/zugang/anfordern', {
        preserveScroll: true,
        onSuccess: () => {
            emailSent.value = true;
            email.reset('email');
        },
        onError: () =>
            toast.error('Die Bestätigungsmail konnte nicht versendet werden.'),
    });
}
</script>

<template>
    <form
        id="email-change"
        class="space-y-4 rounded-xl border p-5"
        @submit.prevent="sendEmailConfirmation"
    >
        <h2 class="text-xl font-medium">E-Mail-Adresse ändern</h2>
        <p class="text-sm">
            Die bisherige Adresse bleibt gültig, bis
            {{
                $address(
                    'du die neue Adresse bestätigst',
                    'Sie die neue Adresse bestätigen',
                )
            }}. {{ $address('Öffne', 'Öffnen Sie') }} den Link im selben
            Browser, während {{ $address('dein', 'Ihr') }}
            Zugang noch aktiv ist.
        </p>
        <Alert v-if="emailSent" variant="success">
            <CircleCheck />
            <AlertTitle>Bestätigungsmail versendet</AlertTitle>
            <AlertDescription>
                {{ $address('Prüfe', 'Prüfen Sie') }} den Posteingang der neuen
                Adresse und {{ $address('öffne', 'öffnen Sie') }} innerhalb von
                15 Minuten den enthaltenen Bestätigungslink.
            </AlertDescription>
        </Alert>
        <div class="space-y-2">
            <Label for="member-new-email">Neue E-Mail-Adresse</Label>
            <Input
                id="member-new-email"
                v-model="email.email"
                type="email"
                autocomplete="email"
                required
            />
        </div>
        <Alert v-if="email.errors.email" variant="destructive">
            <CircleAlert />
            <AlertTitle>Versand nicht möglich</AlertTitle>
            <AlertDescription>{{ email.errors.email }}</AlertDescription>
        </Alert>
        <Button
            :variant="primary ? 'default' : 'outline'"
            :disabled="email.processing"
        >
            Bestätigungslink senden
        </Button>
    </form>
</template>
