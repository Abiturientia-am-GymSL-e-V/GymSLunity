<script setup lang="ts">
import { usePasskeyRegister } from '@laravel/passkeys/vue';
import { router } from '@inertiajs/vue3';
import { KeyRound, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type PasskeyItem = {
    id: string;
    name: string;
    last_used_at: string | null;
    created_at: string | null;
};

const props = withDefaults(
    defineProps<{
        canManagePasskeys?: boolean;
        passkeys?: PasskeyItem[];
        compact?: boolean;
    }>(),
    {
        canManagePasskeys: false,
        passkeys: () => [],
        compact: false,
    },
);

const name = ref('');
const { register, isLoading, error, isSupported } = usePasskeyRegister({
    onSuccess: () => {
        name.value = '';
        router.reload({ only: ['passkeys'] });
    },
});

const addPasskey = async () => {
    const passkeyName = name.value.trim();
    if (!passkeyName) return;

    await register(passkeyName);
};

const removePasskey = (passkey: PasskeyItem) => {
    if (!window.confirm('Passkey „' + passkey.name + '“ wirklich entfernen?')) {
        return;
    }

    router.delete('/user/passkeys/' + encodeURIComponent(passkey.id), {
        preserveScroll: true,
    });
};

const formatDate = (value: string | null) =>
    value ? new Date(value).toLocaleString('de-DE') : 'noch nie';
</script>

<template>
    <section v-if="canManagePasskeys" class="space-y-6">
        <Heading
            v-if="!compact"
            variant="small"
            title="Passkeys"
            :description="
                $address(
                    'Melde dich mit Touch ID, Face ID, Windows Hello oder einem Sicherheitsschlüssel an.',
                    'Melden Sie sich mit Touch ID, Face ID, Windows Hello oder einem Sicherheitsschlüssel an.',
                )
            "
        />

        <div class="space-y-3">
            <div
                v-for="passkey in props.passkeys"
                :key="passkey.id"
                class="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4"
            >
                <div class="flex min-w-0 items-start gap-3">
                    <KeyRound class="mt-0.5 size-4 shrink-0" />
                    <div class="min-w-0 text-sm">
                        <p class="truncate font-medium">{{ passkey.name }}</p>
                        <p class="text-muted-foreground">
                            Zuletzt verwendet:
                            {{ formatDate(passkey.last_used_at) }}
                        </p>
                    </div>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="removePasskey(passkey)"
                >
                    <Trash2 class="size-4" />
                    Entfernen
                </Button>
            </div>
        </div>

        <form class="space-y-3" @submit.prevent="addPasskey">
            <div class="grid gap-2">
                <Label for="passkey-name">Name des Geräts</Label>
                <Input
                    id="passkey-name"
                    v-model="name"
                    type="text"
                    maxlength="100"
                    placeholder="z. B. MacBook oder Windows-PC"
                    autocomplete="off"
                    :disabled="isLoading"
                />
            </div>
            <StatusAlert
                v-if="error"
                type="error"
                title="Passkey konnte nicht hinzugefügt werden"
            >
                {{ error }}
            </StatusAlert>
            <StatusAlert v-else-if="!isSupported" type="info">
                {{
                    $address(
                        'Dieser Browser unterstützt keine Passkeys. Verwende TOTP oder öffne die Seite in einem aktuellen Browser.',
                        'Dieser Browser unterstützt keine Passkeys. Verwenden Sie TOTP oder öffnen Sie die Seite in einem aktuellen Browser.',
                    )
                }}
            </StatusAlert>
            <Button
                type="submit"
                :disabled="isLoading || !isSupported || !name.trim()"
            >
                <KeyRound class="size-4" />
                {{
                    isLoading ? 'Passkey wird erstellt …' : 'Passkey hinzufügen'
                }}
            </Button>
        </form>
    </section>
</template>
