<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import SignaturePad from '@/components/SignaturePad.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps<{
    token: string;
    clubName: string;
    logoUrl: string | null;
    mandate: {
        mandate_reference: string;
        debtor_name: string;
        iban_masked: string;
        mandate_type: 'recurring' | 'one_off';
        mandate_text: string;
        status: 'pending' | 'signed' | 'revoked';
        signed_at: string | null;
        revoked_at: string | null;
    };
}>();
const form = useForm({
    signed_by_name: props.mandate.debtor_name,
    signature_data: null as string | null,
    confirmed: false,
});
</script>

<template>
    <Head title="SEPA-Mandat unterzeichnen" />
    <main class="min-h-screen bg-muted/30 px-4 py-8 sm:py-12">
        <div class="mx-auto max-w-2xl space-y-6">
            <header class="text-center">
                <img
                    v-if="logoUrl"
                    :src="logoUrl"
                    alt=""
                    class="mx-auto mb-4 max-h-20 max-w-52 object-contain"
                />
                <p class="text-sm font-medium text-muted-foreground">
                    {{ clubName }}
                </p>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight">
                    SEPA-Lastschriftmandat
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Mandatsreferenz {{ mandate.mandate_reference }}
                </p>
            </header>
            <StatusAlert
                v-if="mandate.status === 'revoked'"
                type="error"
                title="Mandat wurde widerrufen"
            >
                Dieses Mandat ist nicht mehr gültig und kann nicht mehr
                unterzeichnet werden.
            </StatusAlert>
            <StatusAlert
                v-else-if="mandate.status === 'signed'"
                type="success"
                title="Mandat wurde unterzeichnet"
            >
                Vielen Dank. Dieses Mandat ist bereits vollständig erteilt.
            </StatusAlert>
            <template v-else>
                <section class="space-y-5 rounded-xl border bg-card p-5 sm:p-6">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Zahlungspflichtige Person
                            </p>
                            <p class="font-medium">{{ mandate.debtor_name }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">IBAN</p>
                            <p class="font-mono font-medium">
                                {{ mandate.iban_masked }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Mandatsart
                            </p>
                            <p class="font-medium">
                                {{
                                    mandate.mandate_type === 'one_off'
                                        ? 'Einmalige Zahlung'
                                        : 'Wiederkehrende Zahlungen'
                                }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Referenz
                            </p>
                            <p class="font-mono font-medium">
                                {{ mandate.mandate_reference }}
                            </p>
                        </div>
                    </div>
                    <div
                        class="rounded-lg bg-muted p-4 text-sm leading-relaxed whitespace-pre-line"
                    >
                        {{ mandate.mandate_text }}
                    </div>
                </section>
                <form
                    class="space-y-5 rounded-xl border bg-card p-5 sm:p-6"
                    @submit.prevent="form.post(`/sepa-mandat/${token}`)"
                >
                    <div class="space-y-2">
                        <Label for="signed-by-name"
                            >Name der unterzeichnenden Person *</Label
                        ><Input
                            id="signed-by-name"
                            v-model="form.signed_by_name"
                            maxlength="255"
                            required
                        />
                    </div>
                    <SignaturePad v-model="form.signature_data" /><label
                        class="flex items-start gap-3 text-sm"
                        ><input
                            v-model="form.confirmed"
                            type="checkbox"
                            required
                            class="mt-1"
                        /><span
                            >Ich erteile das oben beschriebene
                            SEPA-Lastschriftmandat und bestätige, dass ich zur
                            Verfügung über das angegebene Konto berechtigt
                            bin.</span
                        ></label
                    ><StatusAlert
                        v-if="Object.keys(form.errors).length"
                        type="error"
                        title="Mandat nicht unterzeichnet"
                        :messages="Object.values(form.errors)"
                    /><Button
                        class="w-full"
                        :disabled="
                            form.processing ||
                            !form.signature_data ||
                            !form.confirmed
                        "
                        ><Spinner v-if="form.processing" />Mandat verbindlich
                        unterzeichnen</Button
                    >
                </form>
            </template>
            <p class="text-center text-xs text-muted-foreground">
                Die vollständigen Mandatsdaten werden nach der Unterzeichnung
                beim Gläubiger revisionssicher gespeichert.
            </p>
        </div>
    </main>
</template>
