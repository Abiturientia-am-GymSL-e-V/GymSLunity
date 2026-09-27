<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <footer class="border-t text-sm text-muted-foreground">
        <div
            class="mx-auto flex w-full max-w-6xl flex-wrap items-center gap-x-6 gap-y-2 px-6 py-6"
        >
            <span class="mr-auto">GymSLunity · Gemeinsam organisiert.</span>
            <nav aria-label="Rechtliche Hinweise" class="flex gap-6">
                <Link href="/impressum" class="hover:text-foreground"
                    >Impressum</Link
                >
                <Link href="/datenschutz" class="hover:text-foreground"
                    >Datenschutzerklärung</Link
                >
            </nav>
        </div>
    </footer>
</template>
