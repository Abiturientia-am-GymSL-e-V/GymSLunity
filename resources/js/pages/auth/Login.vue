<script setup lang="ts">
import { usePasskeyVerify } from '@laravel/passkeys/vue';
import { Form, Head, usePage } from '@inertiajs/vue3';
import { KeyRound } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Verwaltungszugang',
        description: 'Anmeldung mit dem Benutzerkonto.',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();

const demo = usePage().props.demo;

const {
    verify: verifyPasskey,
    isLoading: passkeyLoading,
    error: passkeyError,
    isSupported: passkeySupported,
} = usePasskeyVerify({
    autofill: false,
    onSuccess: (response) => {
        window.location.assign(response.redirect ?? '/dashboard');
    },
});
</script>

<template>
    <Head title="Verwaltungszugang" />

    <StatusAlert v-if="status" type="success" class="mb-4">
        {{ status }}
    </StatusAlert>

    <StatusAlert
        v-if="demo"
        type="info"
        title="Zugangsdaten der Demo"
        class="mb-6"
        data-demo-hint
    >
        <p>
            Alle Konten verwenden das Passwort
            <code class="font-mono font-medium break-all select-all">{{
                demo.password
            }}</code
            >.
        </p>
        <dl class="mt-3 space-y-2">
            <div v-for="account in demo.accounts" :key="account.email">
                <dt class="font-mono font-medium break-all select-all">
                    {{ account.email }}
                </dt>
                <dd>{{ account.roles }}</dd>
            </div>
        </dl>
    </StatusAlert>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">E-Mail-Adresse</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    v-focus
                    :tabindex="1"
                    autocomplete="email webauthn"
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="password">Passwort</Label>
                    <TextLink
                        v-if="canResetPassword"
                        :href="request()"
                        class="text-sm"
                        :tabindex="5"
                    >
                        Passwort vergessen?
                    </TextLink>
                </div>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    :tabindex="2"
                    autocomplete="current-password"
                    placeholder="Passwort"
                />
                <InputError :message="errors.password" />
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                :tabindex="3"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                Anmelden
            </Button>
        </div>
    </Form>

    <div class="my-6 flex items-center gap-3 text-sm text-muted-foreground">
        <div class="h-px flex-1 bg-border" />
        oder
        <div class="h-px flex-1 bg-border" />
    </div>

    <Button
        type="button"
        variant="outline"
        class="w-full"
        :disabled="!passkeySupported || passkeyLoading"
        @click="verifyPasskey"
    >
        <KeyRound class="size-4" />
        {{ passkeyLoading ? 'Passkey wird geprüft …' : 'Mit Passkey anmelden' }}
    </Button>
    <StatusAlert
        v-if="passkeyError"
        type="error"
        title="Passkey-Anmeldung fehlgeschlagen"
        class="mt-3"
    >
        {{ passkeyError }}
    </StatusAlert>
    <p
        v-else-if="!passkeySupported"
        class="mt-2 text-center text-sm text-muted-foreground"
    >
        Passkeys werden von diesem Browser nicht unterstützt.
    </p>
</template>
