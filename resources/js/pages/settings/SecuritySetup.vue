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
        <div
            class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
        >
            <p class="font-medium">Einrichtung erforderlich</p>
            <p class="mt-1 text-sm">
                Dieses Konto besitzt privilegierte Rollen. Richte eine der
                folgenden sicheren Anmeldemethoden ein, bevor du fortfährst.
            </p>
        </div>

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
