<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import AppLogo from '@/components/AppLogo.vue';
import DemoBanner from '@/components/DemoBanner.vue';
import { Button } from '@/components/ui/button';

defineProps<{ signedIn?: boolean; showHome?: boolean }>();
const page = usePage();
</script>

<template>
    <header class="w-full">
        <DemoBanner />
        <div
            class="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-6 py-8"
        >
            <Link href="/" class="flex min-w-0 items-center gap-4">
                <span class="w-40 shrink-0 sm:w-52"><AppLogo /></span>
                <span
                    v-if="page.props.clubName"
                    class="hidden h-9 border-l sm:block"
                    aria-hidden="true"
                />
                <span
                    v-if="page.props.clubName"
                    class="hidden min-w-0 items-center gap-2 sm:flex"
                >
                    <img
                        v-if="page.props.logoUrl"
                        :src="String(page.props.logoUrl)"
                        alt=""
                        class="size-16 shrink-0 object-contain"
                    />
                    <span class="truncate text-sm font-semibold lg:text-base">{{
                        page.props.clubName
                    }}</span>
                </span>
            </Link>

            <Button v-if="signedIn" as-child variant="outline">
                <Link href="/selfservice/abmelden" method="post" as="button"
                    >Abmelden</Link
                >
            </Button>
            <Button v-else-if="showHome" as-child variant="outline">
                <Link href="/">Startseite</Link>
            </Button>
        </div>
    </header>
</template>
