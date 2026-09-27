<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { KeyRound, ShieldCheck } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import ManagePasskeys, {
    type PasskeyItem,
} from '@/components/ManagePasskeys.vue';
import ManageTwoFactor, {
    type Props as ManageTwoFactorProps,
} from '@/components/ManageTwoFactor.vue';
import StatusAlert from '@/components/StatusAlert.vue';

defineProps<
    ManageTwoFactorProps & {
        canManagePasskeys: boolean;
        passkeys: PasskeyItem[];
        status?: string;
    }
>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Anmeldeschutz einrichten',
                href: '/settings/security/setup',
            },
        ],
    },
});
</script>

<template>
    <Head title="Anmeldeschutz einrichten" />

    <div class="space-y-8">
        <StatusAlert type="warning" title="Einrichtung erforderlich">
            {{
                $address(
                    'Dieses Konto besitzt privilegierte Rollen. Richte eine der folgenden sicheren Anmeldemethoden ein, bevor du fortfährst.',
                    'Dieses Konto besitzt privilegierte Rollen. Richten Sie eine der folgenden sicheren Anmeldemethoden ein, bevor Sie fortfahren.',
                )
            }}
        </StatusAlert>

        <section class="space-y-5 rounded-lg border p-5">
            <div class="flex items-start gap-3">
                <KeyRound class="mt-1 size-5 shrink-0" />
                <Heading
                    variant="small"
                    title="Passkey"
                    description="Empfohlen: Touch ID, Face ID, Windows Hello oder Sicherheitsschlüssel."
                />
            </div>
            <ManagePasskeys
                :can-manage-passkeys="canManagePasskeys"
                :passkeys="passkeys"
                compact
            />
        </section>

        <div class="flex items-center gap-3 text-sm text-muted-foreground">
            <div class="h-px flex-1 bg-border" />
            oder
            <div class="h-px flex-1 bg-border" />
        </div>

        <section class="space-y-5 rounded-lg border p-5">
            <div class="flex items-start gap-3">
                <ShieldCheck class="mt-1 size-5 shrink-0" />
                <Heading
                    variant="small"
                    title="Authenticator-App (TOTP)"
                    description="Alternativ erzeugt eine Authenticator-App zeitbasierte Einmalcodes."
                />
            </div>
            <ManageTwoFactor
                :can-manage-two-factor="canManageTwoFactor"
                :requires-confirmation="requiresConfirmation"
                :two-factor-enabled="twoFactorEnabled"
            />
        </section>
    </div>
</template>
