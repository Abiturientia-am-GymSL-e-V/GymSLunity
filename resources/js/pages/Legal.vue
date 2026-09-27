<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Frame from '@/components/selfservice/Frame.vue';

defineProps<{ title: string; text: string }>();
</script>

<template>
    <Head :title="title" />
    <Frame>
        <article class="max-w-4xl space-y-6">
            <h1 class="text-3xl font-semibold tracking-tight">{{ title }}</h1>
            <div
                class="rounded-xl border bg-card p-6 leading-relaxed whitespace-pre-wrap"
            >
                {{ text }}
            </div>
        </article>
    </Frame>
</template>
