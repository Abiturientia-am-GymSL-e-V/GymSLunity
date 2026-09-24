<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import Frame from '@/components/selfservice/Frame.vue';
import { Button } from '@/components/ui/button';
defineProps<{ publicJoin: boolean }>();
const sent = ref(false);
const form = useForm({ email: '', member_number: '', purpose: 'login' });
const confirmation = useForm({ token: '' });
onMounted(() => {
    form.purpose = new URLSearchParams(window.location.search).has('beitritt')
        ? 'join'
        : 'login';
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
                Du erhältst einen einmaligen Link an deine E-Mail-Adresse. Er
                ist 15 Minuten gültig; dein Zugang bleibt anschließend 30
                Minuten geöffnet.
            </p>
            <form
                class="space-y-4 rounded-xl border p-5"
                @submit.prevent="
                    form.post('/selfservice/zugang/anfordern', {
                        onSuccess: () => (sent = true),
                    })
                "
            >
                <label class="block space-y-1"
                    ><span>E-Mail-Adresse</span
                    ><input
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        required
                        class="w-full rounded border bg-background p-2"
                /></label>
                <label class="block space-y-1"
                    ><span>Mitgliedsnummer (optional)</span
                    ><input
                        v-model="form.member_number"
                        inputmode="numeric"
                        class="w-full rounded border bg-background p-2"
                    /><span class="text-xs text-muted-foreground"
                        >Bei gemeinsam genutzten E-Mail-Adressen
                        erforderlich.</span
                    ></label
                >
                <label v-if="publicJoin" class="flex gap-2"
                    ><input
                        v-model="form.purpose"
                        type="checkbox"
                        true-value="join"
                        false-value="login"
                    />
                    Ich möchte Mitglied werden.</label
                >
                <p
                    v-for="error in form.errors"
                    :key="error"
                    class="text-destructive"
                    role="alert"
                >
                    {{ error }}
                </p>
                <Button :disabled="form.processing"
                    >E-Mail-Link anfordern</Button
                >
                <p v-if="sent" role="status" class="text-sm">
                    Wenn ein Zugang möglich ist, erhältst du eine E-Mail. Prüfe
                    auch den Spamordner. Ohne hinterlegte Adresse oder bei
                    Zuordnungsproblemen hilft dir die Vereinsverwaltung.
                </p>
            </form>
            <form
                class="space-y-4 rounded-xl border p-5"
                @submit.prevent="
                    confirmation.post('/selfservice/zugang/bestaetigen')
                "
            >
                <h2 class="text-lg font-medium">E-Mail-Link bestätigen</h2>
                <p class="text-sm">
                    Bestätige den aus deiner E-Mail übernommenen Code oder füge
                    ihn hier ein.
                </p>
                <label class="block space-y-1"
                    ><span>Zugangscode</span
                    ><input
                        v-model="confirmation.token"
                        autocomplete="off"
                        required
                        class="w-full rounded border bg-background p-2 font-mono text-sm"
                /></label>
                <p
                    v-for="error in confirmation.errors"
                    :key="error"
                    class="text-destructive"
                    role="alert"
                >
                    {{ error }}
                </p>
                <Button :disabled="confirmation.processing"
                    >Zugang bestätigen</Button
                >
            </form>
        </div></Frame
    >
</template>
