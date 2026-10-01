<script setup lang="ts">
import { usePasskeyVerify } from '@laravel/passkeys/vue';
import { Head } from '@inertiajs/vue3';
import { KeyRound } from '@lucide/vue';
import StatusAlert from '@/components/StatusAlert.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { dashboard, logout } from '@/routes';
import { confirm, confirmOptions } from '@/routes/passkey';

defineOptions({
    layout: {
        title: 'Anmeldung bestätigen',
        description:
            'Für dieses Konto ist ein zweiter Faktor verpflichtend. Bitte die Anmeldung mit einem registrierten Passkey bestätigen.',
    },
});

const { verify, isLoading, error, isSupported } = usePasskeyVerify({
    routes: { options: confirmOptions.url(), submit: confirm.url() },
    onSuccess: (response) => {
        window.location.assign(response.redirect ?? dashboard.url());
    },
});
</script>

<template>
    <Head title="Anmeldung bestätigen" />

    <div class="space-y-6">
        <Button
            type="button"
            class="w-full"
            :disabled="!isSupported || isLoading"
            data-test="passkey-challenge-button"
            @click="verify"
        >
            <Spinner v-if="isLoading" />
            <KeyRound v-else class="size-4" aria-hidden="true" />
            {{
                isLoading ? 'Passkey wird geprüft …' : 'Mit Passkey bestätigen'
            }}
        </Button>

        <StatusAlert
            v-if="error"
            type="error"
            title="Passkey-Prüfung fehlgeschlagen"
        >
            {{ error }}
        </StatusAlert>
        <StatusAlert
            v-else-if="!isSupported"
            type="warning"
            title="Passkeys nicht verfügbar"
        >
            Dieser Browser unterstützt keine Passkeys. Bitte einen anderen
            Browser oder ein anderes Gerät verwenden.
        </StatusAlert>

        <TextLink :href="logout()" as="button" class="mx-auto block text-sm">
            Abmelden
        </TextLink>
    </div>
</template>
