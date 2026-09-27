<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { MailCheck, Save, Send } from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfigurationNav from '@/components/configuration/ConfigurationNav.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type PublicSettings = {
    driver: string;
    from_address: string;
    from_name: string;
    reply_to_address: string | null;
    reply_to_name: string | null;
    smtp_host: string | null;
    smtp_port: number | null;
    smtp_security: string;
    smtp_username: string | null;
    smtp_password_configured: boolean;
    smtp_timeout: number;
    smtp_local_domain: string | null;
    sendmail_path: string;
    version: number;
};
const props = defineProps<{
    settings: PublicSettings;
    drivers: Record<string, string>;
    securityOptions: Record<string, string>;
    environmentDriver: string;
    nativeAvailable: boolean;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Konfiguration', href: '/konfiguration/verein' },
            { title: 'E-Mail-Versand' },
        ],
    },
});
const form = useForm({
    driver: props.settings.driver,
    from_address: props.settings.from_address,
    from_name: props.settings.from_name,
    reply_to_address: props.settings.reply_to_address || '',
    reply_to_name: props.settings.reply_to_name || '',
    smtp_host: props.settings.smtp_host || '',
    smtp_port: props.settings.smtp_port || 587,
    smtp_security: props.settings.smtp_security,
    smtp_username: props.settings.smtp_username || '',
    smtp_password: '',
    clear_password: false,
    smtp_timeout: props.settings.smtp_timeout,
    smtp_local_domain: props.settings.smtp_local_domain || '',
    sendmail_path: props.settings.sendmail_path,
    version: props.settings.version,
    test_email: '',
});
const passwordConfigured = ref(props.settings.smtp_password_configured);
const testing = ref(false);
const testError = ref('');
const selectedDriver = computed(
    () => props.drivers[form.driver] || form.driver,
);
function save() {
    form.patch('/konfiguration/email', {
        preserveScroll: true,
        onSuccess: () => {
            passwordConfigured.value = props.settings.smtp_password_configured;
            form.defaults({
                ...form.data(),
                version: props.settings.version,
                smtp_password: '',
                clear_password: false,
            });
            form.reset();
        },
    });
}
function testMail() {
    testing.value = true;
    testError.value = '';
    form.post('/konfiguration/email/test', {
        preserveScroll: true,
        preserveState: true,
        onError: (errors) => {
            testError.value = errors.test_email || '';
        },
        onFinish: () => {
            testing.value = false;
        },
    });
}
</script>

<template>
    <Head title="E-Mail-Versand" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Konfiguration</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Absender und Transport für Rechnungen und Systemnachrichten.
            </p>
        </header>
        <ConfigurationNav />

        <Alert variant="info">
            <MailCheck class="size-4" />
            <AlertTitle>Aktiver Transport: {{ selectedDriver }}</AlertTitle>
            <AlertDescription>
                Passwörter werden verschlüsselt gespeichert und nach dem
                Speichern nicht wieder angezeigt. Ein Testversand verwendet
                immer den aktuellen Formularstand.
            </AlertDescription>
        </Alert>

        <form class="space-y-5" novalidate @submit.prevent="save">
            <InputError :message="form.errors.version" role="alert" />
            <section class="rounded-xl border bg-card">
                <h2 class="border-b px-5 py-4 text-sm font-semibold">
                    Absender
                </h2>
                <div class="grid gap-5 p-5 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label for="mail-from-address">E-Mail-Adresse *</Label>
                        <Input
                            id="mail-from-address"
                            v-model="form.from_address"
                            type="email"
                            autocomplete="email"
                            :aria-invalid="!!form.errors.from_address"
                        />
                        <InputError :message="form.errors.from_address" />
                    </div>
                    <div class="space-y-2">
                        <Label for="mail-from-name">Absendername *</Label>
                        <Input
                            id="mail-from-name"
                            v-model="form.from_name"
                            maxlength="255"
                            :aria-invalid="!!form.errors.from_name"
                        />
                        <InputError :message="form.errors.from_name" />
                    </div>
                    <div class="space-y-2">
                        <Label for="mail-reply-address"
                            >Antwortadresse (optional)</Label
                        >
                        <Input
                            id="mail-reply-address"
                            v-model="form.reply_to_address"
                            type="email"
                            :aria-invalid="!!form.errors.reply_to_address"
                        />
                        <InputError :message="form.errors.reply_to_address" />
                    </div>
                    <div class="space-y-2">
                        <Label for="mail-reply-name"
                            >Name der Antwortadresse</Label
                        >
                        <Input
                            id="mail-reply-name"
                            v-model="form.reply_to_name"
                            maxlength="255"
                            :aria-invalid="!!form.errors.reply_to_name"
                        />
                        <InputError :message="form.errors.reply_to_name" />
                    </div>
                </div>
            </section>

            <section class="rounded-xl border bg-card">
                <h2 class="border-b px-5 py-4 text-sm font-semibold">
                    Mail-Transport
                </h2>
                <div class="space-y-5 p-5">
                    <div class="max-w-xl space-y-2">
                        <Label for="mail-driver">Versandart *</Label>
                        <select
                            id="mail-driver"
                            v-model="form.driver"
                            class="h-9 w-full rounded-md border bg-background px-3 text-sm"
                        >
                            <option
                                v-for="(label, value) in drivers"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option>
                        </select>
                        <InputError :message="form.errors.driver" />
                        <p
                            v-if="form.driver === 'environment'"
                            class="text-xs text-muted-foreground"
                        >
                            Verwendet den in der Serverumgebung hinterlegten
                            Transport „{{ environmentDriver }}“.
                        </p>
                        <p
                            v-if="form.driver === 'log'"
                            class="text-xs text-amber-700 dark:text-amber-300"
                        >
                            Nachrichten werden nur ins Anwendungsprotokoll
                            geschrieben und nicht zugestellt.
                        </p>
                        <p
                            v-if="form.driver === 'native' && !nativeAvailable"
                            class="text-xs text-destructive"
                        >
                            In der aktuellen PHP-Konfiguration ist kein nativer
                            Mailtransport erkennbar.
                        </p>
                    </div>

                    <div
                        v-if="form.driver === 'smtp'"
                        class="grid gap-5 rounded-lg border bg-muted/20 p-4 sm:grid-cols-2"
                    >
                        <div class="space-y-2">
                            <Label for="smtp-host">SMTP-Server *</Label>
                            <Input
                                id="smtp-host"
                                v-model="form.smtp_host"
                                placeholder="smtp.example.org"
                                :aria-invalid="!!form.errors.smtp_host"
                            />
                            <InputError :message="form.errors.smtp_host" />
                        </div>
                        <div class="space-y-2">
                            <Label for="smtp-port">Port *</Label>
                            <Input
                                id="smtp-port"
                                v-model="form.smtp_port"
                                type="number"
                                min="1"
                                max="65535"
                                :aria-invalid="!!form.errors.smtp_port"
                            />
                            <InputError :message="form.errors.smtp_port" />
                        </div>
                        <div class="space-y-2">
                            <Label for="smtp-security">Verschlüsselung *</Label>
                            <select
                                id="smtp-security"
                                v-model="form.smtp_security"
                                class="h-9 w-full rounded-md border bg-background px-3 text-sm"
                            >
                                <option
                                    v-for="(label, value) in securityOptions"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ label }}
                                </option>
                            </select>
                            <InputError :message="form.errors.smtp_security" />
                        </div>
                        <div class="space-y-2">
                            <Label for="smtp-timeout"
                                >Zeitlimit (Sekunden)</Label
                            >
                            <Input
                                id="smtp-timeout"
                                v-model="form.smtp_timeout"
                                type="number"
                                min="1"
                                max="120"
                                :aria-invalid="!!form.errors.smtp_timeout"
                            />
                            <InputError :message="form.errors.smtp_timeout" />
                        </div>
                        <div class="space-y-2">
                            <Label for="smtp-user"
                                >Benutzername (optional)</Label
                            >
                            <Input
                                id="smtp-user"
                                v-model="form.smtp_username"
                                autocomplete="username"
                                :aria-invalid="!!form.errors.smtp_username"
                            />
                            <InputError :message="form.errors.smtp_username" />
                        </div>
                        <div class="space-y-2">
                            <Label for="smtp-password">Passwort</Label>
                            <Input
                                id="smtp-password"
                                v-model="form.smtp_password"
                                type="password"
                                autocomplete="new-password"
                                :placeholder="
                                    passwordConfigured
                                        ? 'Gespeichertes Passwort beibehalten'
                                        : 'Optional'
                                "
                                :disabled="form.clear_password"
                                :aria-invalid="!!form.errors.smtp_password"
                            />
                            <InputError :message="form.errors.smtp_password" />
                            <label
                                v-if="passwordConfigured"
                                class="flex items-center gap-2 text-xs text-muted-foreground"
                                ><input
                                    v-model="form.clear_password"
                                    type="checkbox"
                                />Gespeichertes Passwort entfernen</label
                            >
                        </div>
                        <div class="space-y-2 sm:col-span-2">
                            <Label for="smtp-domain"
                                >EHLO-Domain (optional)</Label
                            >
                            <Input
                                id="smtp-domain"
                                v-model="form.smtp_local_domain"
                                placeholder="verein.example.org"
                                :aria-invalid="!!form.errors.smtp_local_domain"
                            />
                            <InputError
                                :message="form.errors.smtp_local_domain"
                            />
                        </div>
                    </div>

                    <div
                        v-if="form.driver === 'sendmail'"
                        class="max-w-2xl space-y-2 rounded-lg border bg-muted/20 p-4"
                    >
                        <Label for="sendmail-path">Sendmail-Befehl *</Label>
                        <Input
                            id="sendmail-path"
                            v-model="form.sendmail_path"
                            placeholder="/usr/sbin/sendmail -bs -i"
                            :aria-invalid="!!form.errors.sendmail_path"
                        />
                        <InputError :message="form.errors.sendmail_path" />
                        <p class="text-xs text-muted-foreground">
                            Zulässig sind absolute Pfade zu „sendmail“ mit den
                            sicheren Parametern -bs/-t und -i/-oi.
                        </p>
                    </div>
                </div>
            </section>

            <section class="rounded-xl border bg-card">
                <h2 class="border-b px-5 py-4 text-sm font-semibold">
                    Testversand
                </h2>
                <div class="space-y-4 p-5">
                    <p class="text-sm text-muted-foreground">
                        Testet die aktuell sichtbaren Eingaben, auch wenn sie
                        noch nicht gespeichert wurden.
                    </p>
                    <div class="flex max-w-2xl flex-col gap-2 sm:flex-row">
                        <div class="min-w-0 flex-1">
                            <Label for="test-email" class="sr-only"
                                >Testempfänger</Label
                            >
                            <Input
                                id="test-email"
                                v-model="form.test_email"
                                type="email"
                                placeholder="test@example.org"
                                :aria-invalid="
                                    !!form.errors.test_email || !!testError
                                "
                            />
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="testing || !form.test_email"
                            @click="testMail"
                            ><Spinner v-if="testing" /><Send
                                v-else
                                class="size-4"
                            />Test-E-Mail senden</Button
                        >
                    </div>
                    <InputError
                        :message="testError || form.errors.test_email"
                        role="alert"
                    />
                </div>
            </section>

            <div
                class="sticky bottom-0 flex justify-end border-t bg-background/95 py-3 backdrop-blur"
            >
                <Button type="submit" :disabled="form.processing || testing"
                    ><Spinner v-if="form.processing" /><Save
                        v-else
                        class="size-4"
                    />E-Mail-Konfiguration speichern</Button
                >
            </div>
        </form>
    </div>
</template>
