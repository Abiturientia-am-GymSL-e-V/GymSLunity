<script setup lang="ts">
import { Form, Head, useForm, usePage } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Profileinstellungen',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
const props = defineProps<{ hasProfileSignature: boolean }>();
const signatureInput = ref<HTMLInputElement>();
const signatureVersion = ref(Date.now());
const signatureForm = useForm<{ signature: File | null }>({ signature: null });
const deleteSignatureForm = useForm({});

function uploadSignature() {
    signatureForm.post('/settings/profile/signature', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            signatureForm.reset();
            if (signatureInput.value) signatureInput.value.value = '';
            signatureVersion.value = Date.now();
        },
    });
}

function removeSignature() {
    deleteSignatureForm.delete('/settings/profile/signature', {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Profileinstellungen" />

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Profil"
            description="Ändere deinen Namen und deine E-Mail-Adresse"
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    placeholder="Vollständiger Name"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">E-Mail-Adresse</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    placeholder="E-Mail-Adresse"
                />
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <div v-if="page.props.mustVerifyEmail && !user.email_verified_at">
                <p class="-mt-4 text-sm text-muted-foreground">
                    Deine E-Mail-Adresse ist noch nicht bestätigt.
                    <Link
                        :href="send()"
                        as="button"
                        class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                    >
                        Bestätigungs-E-Mail erneut senden.
                    </Link>
                </p>

                <div
                    v-if="page.props.status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-green-600"
                >
                    Ein neuer Bestätigungslink wurde an deine E-Mail-Adresse
                    gesendet.
                </div>
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-profile-button"
                    >Speichern</Button
                >
            </div>
        </Form>

        <div class="space-y-6 border-t pt-8">
            <Heading
                variant="small"
                title="Unterschrift"
                description="Hinterlege eine Unterschrift, die du beim Unterzeichnen von Dokumenten verwenden kannst."
            />

            <div
                v-if="props.hasProfileSignature"
                class="flex min-h-28 items-center justify-center rounded-lg border bg-white p-4"
            >
                <img
                    :src="`/settings/profile/signature?v=${signatureVersion}`"
                    alt="Im Profil gespeicherte Unterschrift"
                    class="max-h-24 max-w-full"
                />
            </div>

            <form class="space-y-4" @submit.prevent="uploadSignature">
                <div class="grid gap-2">
                    <Label for="profile-signature">Unterschriftsgrafik</Label>
                    <Input
                        id="profile-signature"
                        ref="signatureInput"
                        type="file"
                        accept="image/png,image/jpeg,image/webp"
                        required
                        @change="
                            signatureForm.signature =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] ?? null
                        "
                    />
                    <InputError :message="signatureForm.errors.signature" />
                    <p class="text-xs text-muted-foreground">
                        PNG, JPEG oder WebP, maximal 2 MB. Am besten eignet sich
                        eine freigestellte Unterschrift auf transparentem
                        Hintergrund.
                    </p>
                </div>

                <div class="flex flex-wrap gap-3">
                    <Button
                        type="submit"
                        :disabled="
                            signatureForm.processing || !signatureForm.signature
                        "
                    >
                        {{
                            props.hasProfileSignature
                                ? 'Unterschrift ersetzen'
                                : 'Unterschrift speichern'
                        }}
                    </Button>
                    <Button
                        v-if="props.hasProfileSignature"
                        type="button"
                        variant="outline"
                        :disabled="deleteSignatureForm.processing"
                        @click="removeSignature"
                    >
                        Entfernen
                    </Button>
                </div>
            </form>
        </div>
    </div>

    <DeleteUser />
</template>
