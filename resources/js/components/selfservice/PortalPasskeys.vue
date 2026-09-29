<script setup lang="ts">
import { usePasskeyRegister } from '@laravel/passkeys/vue';
import { router } from '@inertiajs/vue3';
import { KeyRound, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/format';

export type PortalPasskey = {
    id: number;
    name: string;
    last_used_at: string | null;
    created_at: string | null;
};

defineProps<{ passkeys: PortalPasskey[] }>();

const name = ref('');
const { register, isLoading, error, isSupported } = usePasskeyRegister({
    routes: {
        options: '/selfservice/passkeys/optionen',
        submit: '/selfservice/passkeys',
    },
    onSuccess: () => {
        name.value = '';
        router.reload({ only: ['passkeys'] });
    },
});

const addPasskey = async () => {
    const passkeyName = name.value.trim();
    if (passkeyName) await register(passkeyName);
};

const removePasskey = (passkey: PortalPasskey) => {
    if (!window.confirm(`Passkey „${passkey.name}“ wirklich entfernen?`)) {
        return;
    }
    router.delete(`/selfservice/passkeys/${passkey.id}`, {
        preserveScroll: true,
    });
};
</script>

<template>
    <section class="space-y-4 rounded-xl border p-5">
        <div>
            <h2 class="text-xl font-medium">Anmeldung mit Passkey</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                {{
                    $address(
                        'Mit einem Passkey meldest du dich ohne E-Mail-Link an, zum Beispiel per Fingerabdruck, Gesichtserkennung oder Geräte-PIN.',
                        'Mit einem Passkey melden Sie sich ohne E-Mail-Link an, zum Beispiel per Fingerabdruck, Gesichtserkennung oder Geräte-PIN.',
                    )
                }}
            </p>
        </div>
        <ul v-if="passkeys.length" class="space-y-2">
            <li
                v-for="passkey in passkeys"
                :key="passkey.id"
                class="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
            >
                <div class="flex min-w-0 items-start gap-3 text-sm">
                    <KeyRound class="mt-0.5 size-4 shrink-0" />
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ passkey.name }}</p>
                        <p class="text-muted-foreground">
                            Zuletzt verwendet:
                            {{
                                passkey.last_used_at
                                    ? formatDateTime(passkey.last_used_at)
                                    : 'noch nie'
                            }}
                        </p>
                    </div>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="removePasskey(passkey)"
                    ><Trash2 class="size-4" /> Entfernen</Button
                >
            </li>
        </ul>
        <form
            class="flex flex-wrap items-end gap-3"
            @submit.prevent="addPasskey"
        >
            <div class="grid min-w-0 flex-1 basis-60 gap-2">
                <Label for="portal-passkey-name">Name des Geräts</Label>
                <Input
                    id="portal-passkey-name"
                    v-model="name"
                    maxlength="100"
                    placeholder="z. B. Smartphone oder Laptop"
                    autocomplete="off"
                    :disabled="isLoading"
                />
            </div>
            <Button
                type="submit"
                :disabled="isLoading || !isSupported || !name.trim()"
                ><KeyRound class="size-4" />
                {{
                    isLoading ? 'Passkey wird erstellt …' : 'Passkey hinzufügen'
                }}</Button
            >
        </form>
        <StatusAlert
            v-if="error"
            type="error"
            title="Passkey konnte nicht hinzugefügt werden"
            >{{ error }}</StatusAlert
        >
        <p v-else-if="!isSupported" class="text-sm text-muted-foreground">
            Dieser Browser unterstützt keine Passkeys.
        </p>
    </section>
</template>
