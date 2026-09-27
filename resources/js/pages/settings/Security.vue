<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/security';
import type { Props as ManageTwoFactorProps } from '@/components/ManageTwoFactor.vue';
import ManageTwoFactor from '@/components/ManageTwoFactor.vue';
import ManagePasskeys, {
    type PasskeyItem,
} from '@/components/ManagePasskeys.vue';

// oxfmt-ignore
type Props = {
    passwordRules: string;
    canManagePasskeys: boolean;
    passkeys: PasskeyItem[];
    sessions: Array<{
        id: string;
        ip_address: string | null;
        user_agent: string;
        last_active_at: string;
        current: boolean;
    }>;
} &
    ManageTwoFactorProps;

const props = defineProps<Props>();

const endSession = (id: string) =>
    router.delete(`/settings/security/sessions/${encodeURIComponent(id)}`, {
        preserveScroll: true,
    });

const endOtherSessions = () =>
    router.delete('/settings/security/sessions/others', {
        preserveScroll: true,
    });

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Sicherheitseinstellungen',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Sicherheitseinstellungen" />

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Passwort ändern"
            :description="
                $address(
                    'Verwende ein langes, zufälliges Passwort für dein Konto.',
                    'Verwenden Sie ein langes, zufälliges Passwort für Ihr Konto.',
                )
            "
        />

        <Form
            v-bind="SecurityController.update.form()"
            :options="{
                preserveScroll: true,
            }"
            reset-on-success
            :reset-on-error="[
                'password',
                'password_confirmation',
                'current_password',
            ]"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="current_password">Aktuelles Passwort</Label>
                <PasswordInput
                    id="current_password"
                    name="current_password"
                    class="mt-1 block w-full"
                    autocomplete="current-password"
                    placeholder="Aktuelles Passwort"
                />
                <InputError :message="errors.current_password" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Neues Passwort</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    placeholder="Neues Passwort"
                    :passwordrules="props.passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Passwort bestätigen</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    placeholder="Passwort bestätigen"
                    :passwordrules="props.passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <div class="flex items-center gap-4">
                <Button
                    :disabled="processing"
                    data-test="update-password-button"
                >
                    Speichern
                </Button>
            </div>
        </Form>
    </div>

    <ManageTwoFactor
        :canManageTwoFactor="canManageTwoFactor"
        :requiresConfirmation="requiresConfirmation"
        :twoFactorEnabled="twoFactorEnabled"
    />

    <ManagePasskeys
        :can-manage-passkeys="canManagePasskeys"
        :passkeys="passkeys"
    />

    <section class="space-y-4">
        <Heading
            variant="small"
            title="Aktive Sitzungen"
            :description="
                $address(
                    'Beende Zugriffe auf Geräten, die du nicht mehr verwendest. Privilegierte Sitzungen enden nach 30 Minuten Inaktivität.',
                    'Beenden Sie Zugriffe auf Geräten, die Sie nicht mehr verwenden. Privilegierte Sitzungen enden nach 30 Minuten Inaktivität.',
                )
            "
        />
        <div v-if="sessions.length" class="divide-y rounded-lg border">
            <div
                v-for="session in sessions"
                :key="session.id"
                class="flex flex-wrap items-center justify-between gap-3 p-4"
            >
                <div class="min-w-0 text-sm">
                    <p class="font-medium">
                        {{ session.user_agent }}
                        <span
                            v-if="session.current"
                            class="text-green-700 dark:text-green-400"
                        >
                            · Diese Sitzung</span
                        >
                    </p>
                    <p class="text-muted-foreground">
                        {{ session.ip_address ?? 'IP unbekannt' }} · zuletzt
                        aktiv
                        {{
                            new Date(session.last_active_at).toLocaleString(
                                'de-DE',
                            )
                        }}
                    </p>
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    @click="endSession(session.id)"
                >
                    Beenden
                </Button>
            </div>
        </div>
        <p v-else class="text-sm text-muted-foreground">
            Die Sitzungsverwaltung benötigt den Datenbank-Sitzungstreiber.
        </p>
        <Button
            v-if="sessions.some((session) => !session.current)"
            variant="outline"
            @click="endOtherSessions"
        >
            Alle anderen Sitzungen beenden
        </Button>
    </section>
</template>
