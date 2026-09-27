<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import Frame from '@/components/selfservice/Frame.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const sent = ref(false);
const form = useForm({ email: '', member_number: '', purpose: 'join' });
const confirmation = useForm({ token: '' });

onMounted(() => {
    const token = new URLSearchParams(window.location.hash.slice(1)).get(
        'token',
    );
    if (token) {
        confirmation.token = token;
        window.history.replaceState(null, '', window.location.pathname);
    }
});
</script>

<template>
    <Head title="Mitglied werden" />
    <Frame>
        <div class="max-w-xl space-y-6">
            <div class="space-y-2">
                <h1 class="text-3xl font-semibold">Mitglied werden</h1>
                <p class="text-muted-foreground">
                    {{
                        $address(
                            'Bestätige zuerst deine E-Mail-Adresse. Danach öffnet sich der digitale Mitgliedsantrag.',
                            'Bestätigen Sie zuerst Ihre E-Mail-Adresse. Danach öffnet sich der digitale Mitgliedsantrag.',
                        )
                    }}
                </p>
            </div>
            <form
                class="space-y-4 rounded-xl border bg-card p-5"
                @submit.prevent="
                    form.post('/selfservice/zugang/anfordern', {
                        onSuccess: () => (sent = true),
                    })
                "
            >
                <div class="space-y-2">
                    <Label for="join-email">E-Mail-Adresse</Label>
                    <Input
                        id="join-email"
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        required
                    />
                </div>
                <StatusAlert
                    v-if="Object.keys(form.errors).length"
                    type="error"
                    title="Bestätigungslink nicht angefordert"
                    :messages="Object.values(form.errors)"
                />
                <Button :disabled="form.processing"
                    >Bestätigungslink anfordern</Button
                >
                <StatusAlert v-if="sent" type="info" title="Anfrage erhalten">
                    {{
                        $address(
                            'Wenn die Adresse verwendet werden kann, erhältst du eine E-Mail. Prüfe bitte auch den Spamordner.',
                            'Wenn die Adresse verwendet werden kann, erhalten Sie eine E-Mail. Prüfen Sie bitte auch den Spamordner.',
                        )
                    }}
                </StatusAlert>
            </form>
            <form
                class="space-y-4 rounded-xl border bg-card p-5"
                @submit.prevent="
                    confirmation.post('/selfservice/zugang/bestaetigen')
                "
            >
                <h2 class="text-lg font-medium">E-Mail-Adresse bestätigen</h2>
                <p class="text-sm text-muted-foreground">
                    {{
                        $address(
                            'Übernimm den Bestätigungscode aus deiner E-Mail oder füge ihn hier ein. Anschließend öffnet sich der Mitgliedsantrag.',
                            'Übernehmen Sie den Bestätigungscode aus Ihrer E-Mail oder fügen Sie ihn hier ein. Anschließend öffnet sich der Mitgliedsantrag.',
                        )
                    }}
                </p>
                <div class="space-y-2">
                    <Label for="join-token">Bestätigungscode</Label>
                    <Input
                        id="join-token"
                        v-model="confirmation.token"
                        autocomplete="one-time-code"
                        required
                        class="font-mono"
                    />
                </div>
                <StatusAlert
                    v-if="Object.keys(confirmation.errors).length"
                    type="error"
                    title="E-Mail-Adresse nicht bestätigt"
                    :messages="Object.values(confirmation.errors)"
                />
                <Button :disabled="confirmation.processing"
                    >E-Mail-Adresse bestätigen</Button
                >
            </form>
        </div>
    </Frame>
</template>
