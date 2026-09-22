<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Hallo Welt' }],
    },
});

const text = ref('Hallo Welt');
</script>

<template>
    <Head title="Hallo Welt" />

    <div class="mx-auto w-full max-w-6xl space-y-6 p-4 sm:p-6">
        <h1 class="text-2xl font-semibold">Hallo Welt</h1>

        <div class="max-w-md space-y-2">
            <Label for="hello-text">Dein Text</Label>
            <Input id="hello-text" v-model="text" />
            <hr />
            <p>Aktueller Inhalt: {{ text }}</p>
        </div>
    </div>
</template>
