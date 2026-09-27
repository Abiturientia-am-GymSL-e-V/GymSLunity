<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import StatusAlert from '@/components/StatusAlert.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        title: 'E-Mail-Adresse bestätigen',
        description: 'E-Mail-Adresse über den zugesandten Link bestätigen.',
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head title="E-Mail-Adresse bestätigen" />

    <StatusAlert
        v-if="status === 'verification-link-sent'"
        type="success"
        class="mb-4"
    >
        Ein neuer Bestätigungslink wurde an die hinterlegte E-Mail-Adresse
        gesendet.
    </StatusAlert>

    <Form
        v-bind="send.form()"
        class="space-y-6 text-center"
        v-slot="{ processing }"
    >
        <Button :disabled="processing" variant="secondary">
            <Spinner v-if="processing" />
            Bestätigungs-E-Mail erneut senden
        </Button>

        <TextLink :href="logout()" as="button" class="mx-auto block text-sm">
            Abmelden
        </TextLink>
    </Form>
</template>
