<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import Frame from '@/components/selfservice/Frame.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
const sent = ref(false);
const form = useForm({ email: '', member_number: '', purpose: 'login' });
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
    <Head title="Mitgliederzugang" /><Frame
        ><div class="max-w-xl space-y-6">
            <h1 class="text-3xl font-semibold">Mitgliederzugang</h1>
            <p>
                {{
                    $address(
                        'Du erhältst einen einmaligen Link an deine E-Mail-Adresse. Er ist 15 Minuten gültig; dein Zugang bleibt anschließend 30 Minuten geöffnet.',
                        'Sie erhalten einen einmaligen Link an Ihre E-Mail-Adresse. Er ist 15 Minuten gültig; Ihr Zugang bleibt anschließend 30 Minuten geöffnet.',
                    )
                }}
            </p>
            <form
                class="space-y-4 rounded-xl border p-5"
                @submit.prevent="
                    form.post('/selfservice/zugang/anfordern', {
                        onSuccess: () => (sent = true),
                    })
                "
            >
                <div class="space-y-2">
                    <Label for="member-access-email">E-Mail-Adresse</Label>
                    <Input
                        id="member-access-email"
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                    />
                </div>
                <div class="space-y-2">
                    <Label for="member-access-number">Mitgliedsnummer</Label>
                    <Input
                        id="member-access-number"
                        v-model="form.member_number"
                        inputmode="numeric"
                    />
                    <p class="text-xs text-muted-foreground">
                        Eine Angabe genügt. Wird eine E-Mail-Adresse von
                        mehreren Personen genutzt,
                        {{ $address('gib', 'geben Sie') }} bitte E-Mail-Adresse
                        und Mitgliedsnummer an.
                    </p>
                </div>
                <StatusAlert
                    v-if="Object.keys(form.errors).length"
                    type="error"
                    title="Zugangslink nicht angefordert"
                    :messages="Object.values(form.errors)"
                />
                <Button :disabled="form.processing"
                    >E-Mail-Link anfordern</Button
                >
                <StatusAlert v-if="sent" type="info" title="Anfrage erhalten">
                    {{
                        $address(
                            'Wenn ein Zugang möglich ist, erhältst du eine E-Mail. Prüfe auch den Spamordner. Ohne hinterlegte Adresse oder bei Zuordnungsproblemen hilft dir die Vereinsverwaltung.',
                            'Wenn ein Zugang möglich ist, erhalten Sie eine E-Mail. Prüfen Sie auch den Spamordner. Ohne hinterlegte Adresse oder bei Zuordnungsproblemen hilft Ihnen die Vereinsverwaltung.',
                        )
                    }}
                </StatusAlert>
            </form>
            <form
                class="space-y-4 rounded-xl border p-5"
                @submit.prevent="
                    confirmation.post('/selfservice/zugang/bestaetigen')
                "
            >
                <h2 class="text-lg font-medium">E-Mail-Link bestätigen</h2>
                <p class="text-sm">
                    {{
                        $address(
                            'Bestätige den aus deiner E-Mail übernommenen Code oder füge ihn hier ein.',
                            'Bestätigen Sie den aus Ihrer E-Mail übernommenen Code oder fügen Sie ihn hier ein.',
                        )
                    }}
                </p>
                <div class="space-y-2">
                    <Label for="member-access-token">Zugangscode</Label>
                    <Input
                        id="member-access-token"
                        v-model="confirmation.token"
                        autocomplete="off"
                        required
                        class="font-mono"
                    />
                </div>
                <StatusAlert
                    v-if="Object.keys(confirmation.errors).length"
                    type="error"
                    title="Zugang nicht bestätigt"
                    :messages="Object.values(confirmation.errors)"
                />
                <Button :disabled="confirmation.processing"
                    >Zugang bestätigen</Button
                >
            </form>
        </div></Frame
    >
</template>
