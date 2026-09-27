<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Eye, EyeOff, LockKeyhole, RefreshCw } from '@lucide/vue';
import { nextTick, onMounted, ref, useTemplateRef } from 'vue';
import AlertError from '@/components/AlertError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { regenerateRecoveryCodes } from '@/routes/two-factor';

const { recoveryCodesList, fetchRecoveryCodes, errors } = useTwoFactorAuth();
const isRecoveryCodesVisible = ref<boolean>(false);
const recoveryCodeSectionRef = useTemplateRef('recoveryCodeSectionRef');

const toggleRecoveryCodesVisibility = async () => {
    if (!isRecoveryCodesVisible.value && !recoveryCodesList.value.length) {
        await fetchRecoveryCodes();
    }

    isRecoveryCodesVisible.value = !isRecoveryCodesVisible.value;

    if (isRecoveryCodesVisible.value) {
        await nextTick();
        recoveryCodeSectionRef.value?.scrollIntoView({ behavior: 'smooth' });
    }
};

onMounted(async () => {
    if (!recoveryCodesList.value.length) {
        await fetchRecoveryCodes();
    }
});
</script>

<template>
    <Card class="w-full">
        <CardHeader>
            <CardTitle class="flex gap-3">
                <LockKeyhole class="size-4" />2FA-Wiederherstellungscodes
            </CardTitle>
            <CardDescription>
                {{
                    $address(
                        'Mit Wiederherstellungscodes kannst du dich anmelden, falls du den Zugriff auf deine Authenticator-App verlierst. Bewahre sie in einem sicheren Passwortmanager auf.',
                        'Mit Wiederherstellungscodes können Sie sich anmelden, falls Sie den Zugriff auf Ihre Authenticator-App verlieren. Bewahren Sie sie in einem sicheren Passwortmanager auf.',
                    )
                }}
            </CardDescription>
        </CardHeader>
        <CardContent>
            <div
                class="flex flex-col gap-3 select-none sm:flex-row sm:items-center sm:justify-between"
            >
                <Button @click="toggleRecoveryCodesVisibility" class="w-fit">
                    <component
                        :is="isRecoveryCodesVisible ? EyeOff : Eye"
                        class="size-4"
                    />
                    {{
                        isRecoveryCodesVisible
                            ? 'Codes ausblenden'
                            : 'Codes anzeigen'
                    }}
                </Button>

                <Form
                    v-if="isRecoveryCodesVisible && recoveryCodesList.length"
                    v-bind="regenerateRecoveryCodes.form()"
                    method="post"
                    :options="{ preserveScroll: true }"
                    @success="fetchRecoveryCodes"
                    #default="{ processing }"
                >
                    <Button
                        variant="secondary"
                        type="submit"
                        :disabled="processing"
                    >
                        <RefreshCw /> Neue Codes erstellen
                    </Button>
                </Form>
            </div>
            <div
                :class="[
                    'relative overflow-hidden transition-all duration-300',
                    isRecoveryCodesVisible
                        ? 'h-auto opacity-100'
                        : 'h-0 opacity-0',
                ]"
            >
                <div v-if="errors?.length" class="mt-6">
                    <AlertError :errors="errors" />
                </div>
                <div v-else class="mt-3 space-y-3">
                    <div
                        ref="recoveryCodeSectionRef"
                        class="grid gap-1 rounded-lg bg-muted p-4 font-mono text-sm"
                    >
                        <div v-if="!recoveryCodesList.length" class="space-y-2">
                            <div
                                v-for="n in 8"
                                :key="n"
                                class="h-4 animate-pulse rounded bg-muted-foreground/20"
                            ></div>
                        </div>
                        <div
                            v-else
                            v-for="(code, index) in recoveryCodesList"
                            :key="index"
                        >
                            {{ code }}
                        </div>
                    </div>
                    <p class="text-xs text-muted-foreground select-none">
                        Jeder Wiederherstellungscode kann nur einmal verwendet
                        werden.
                        {{
                            $address(
                                'Bei Bedarf kannst du',
                                'Bei Bedarf können Sie',
                            )
                        }}
                        über
                        <span class="font-bold">Neue Codes erstellen</span> die
                        bisherigen Codes ersetzen.
                    </p>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
