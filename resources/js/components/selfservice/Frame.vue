<script setup lang="ts">
import PublicFooter from '@/components/public/PublicFooter.vue';
import PublicHeader from '@/components/public/PublicHeader.vue';
import { Toaster } from '@/components/ui/sonner';

defineProps<{ signedIn?: boolean }>();
</script>
<template>
    <div class="flex min-h-svh flex-col bg-background text-foreground">
        <PublicHeader :signed-in="signedIn" :show-home="!signedIn" />
        <main
            class="mx-auto w-full max-w-6xl flex-1 space-y-6 px-6 py-10 sm:py-14"
        >
            <slot />
        </main>
        <PublicFooter />
        <Toaster position="top-right" close-button rich-colors />
    </div>
</template>
