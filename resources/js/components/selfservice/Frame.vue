<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogo from '@/components/AppLogo.vue';
defineProps<{ signedIn?: boolean }>();
</script>
<template>
    <div class="min-h-svh bg-background text-foreground">
        <header
            class="mx-auto flex max-w-4xl items-center justify-between gap-4 px-5 py-7"
        >
            <Link href="/" class="w-48"><AppLogo /></Link
            ><Link
                v-if="signedIn"
                href="/selfservice/abmelden"
                method="post"
                as="button"
                class="text-sm underline"
                >Abmelden</Link
            ><Link v-else href="/" class="text-sm underline">Startseite</Link>
        </header>
        <main class="mx-auto max-w-4xl space-y-6 px-5 pb-12"><slot /></main>
    </div>
</template>
