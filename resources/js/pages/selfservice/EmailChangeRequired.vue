<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import EmailChangeForm from '@/components/selfservice/EmailChangeForm.vue';
import Frame from '@/components/selfservice/Frame.vue';
import StatusAlert from '@/components/StatusAlert.vue';

defineProps<{
    member: { member_number: number; first_name: string; email: string };
    pendingEmail: string | null;
}>();
</script>

<template>
    <Head title="E-Mail-Adresse ändern" />
    <Frame signed-in>
        <h1 class="text-3xl font-semibold">Hallo {{ member.first_name }}</h1>
        <p class="break-words">
            Nr. {{ member.member_number }} · {{ member.email }}
        </p>
        <StatusAlert type="warning" title="Neue E-Mail-Adresse erforderlich">
            Die hinterlegte Adresse ist für den Mitgliederbereich nicht mehr
            zugelassen.
            {{
                $address(
                    'Bitte hinterlege eine andere Adresse und bestätige sie über den Link, den du per E-Mail erhältst.',
                    'Bitte hinterlegen Sie eine andere Adresse und bestätigen Sie sie über den Link, den Sie per E-Mail erhalten.',
                )
            }}
            Bis dahin ist der Mitgliederbereich eingeschränkt; die bisherige
            Adresse bleibt für die Anmeldung gültig.
        </StatusAlert>
        <StatusAlert
            v-if="pendingEmail"
            type="info"
            title="Bestätigung ausstehend"
        >
            Ein Bestätigungslink wurde an {{ pendingEmail }} gesendet.
            {{
                $address(
                    'Du kannst auch eine andere Adresse angeben.',
                    'Sie können auch eine andere Adresse angeben.',
                )
            }}
        </StatusAlert>
        <EmailChangeForm primary />
    </Frame>
</template>
