<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { FlaskConical } from '@lucide/vue';
import { index as mailbox } from '@/routes/demo/mailbox';

const page = usePage();
</script>

<template>
    <div
        v-if="page.props.demo"
        role="note"
        data-demo-hint
        class="flex w-full items-start justify-center gap-2 border-b bg-primary px-4 py-2 text-sm text-primary-foreground sm:items-center"
    >
        <FlaskConical
            class="mt-0.5 size-4 shrink-0 sm:mt-0"
            aria-hidden="true"
        />
        <p class="min-w-0">
            <span class="font-medium">Öffentliche Demo:</span>
            Alle Daten werden täglich um {{ page.props.demo.resetAt }} Uhr
            zurückgesetzt. Bitte keine echten personenbezogenen Daten eingeben.
            Versendete E-Mails stehen im
            <Link :href="mailbox.url()" class="font-medium underline">
                Demo-Postfach</Link
            >.
        </p>
    </div>
</template>
