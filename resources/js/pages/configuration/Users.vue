<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { KeyRound, MailPlus, Pencil, Plus, Search } from '@lucide/vue';
import { ref } from 'vue';
import ConfigurationNav from '@/components/configuration/ConfigurationNav.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index, invite, store, update } from '@/routes/configuration/users';

type Account = {
    id: number;
    name: string;
    email: string;
    roles: string[] | null;
    is_active: boolean;
    lock_version: number;
    email_verified_at: string | null;
};
const props = defineProps<{
    users: {
        data: Account[];
        total: number;
        current_page: number;
        last_page: number;
        next_page_url: string | null;
        prev_page_url: string | null;
    };
    q: string;
    roles: Record<string, string>;
    descriptions: Record<string, string>;
    areas: Record<string, string[]>;
    invitationHours: number;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Konfiguration', href: index() },
            { title: 'Benutzer & Rechte' },
        ],
    },
});
const page = usePage();
const search = ref(props.q);
const selected = ref<Account | null>(null);
const open = ref(false);
const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    roles: [] as string[],
    is_active: true,
    verified: true,
    lock_version: 0,
    send_invitation: true,
});
const inviting = ref<number | null>(null);
function edit(user: Account | null) {
    selected.value = user;
    form.defaults({
        name: user?.name || '',
        email: user?.email || '',
        password: '',
        password_confirmation: '',
        roles: [...(user?.roles || [])],
        is_active: user?.is_active ?? true,
        verified: user ? !!user.email_verified_at : true,
        lock_version: user?.lock_version ?? 0,
        send_invitation: !user,
    });
    form.reset();
    form.clearErrors();
    open.value = true;
}
function save() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset('password', 'password_confirmation');
        },
    };
    if (selected.value) form.patch(update.url(selected.value.id), options);
    else form.post(store.url(), options);
}
function sendInvitation(user: Account) {
    if (
        !window.confirm(
            `Eine neue Zugangsmail an ${user.email} senden? Frühere Links werden ungültig.`,
        )
    )
        return;
    inviting.value = user.id;
    router.post(
        invite.url(user.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => (inviting.value = null),
        },
    );
}
function close(value: boolean) {
    if (!value && form.processing) return;
    if (
        !value &&
        form.isDirty &&
        !window.confirm('Ungespeicherte Kontoänderungen verwerfen?')
    )
        return;
    open.value = value;
    if (!value) form.reset('password', 'password_confirmation');
}
</script>

<template>
    <Head title="Benutzer & Rechte" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Konfiguration
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Zugänge und Berechtigungen für die Verwaltung.
                </p>
            </div>
            <Button data-test="new-user" @click="edit(null)"
                ><Plus class="size-4" />Benutzer anlegen</Button
            >
        </div>
        <ConfigurationNav />
        <form
            class="flex max-w-lg gap-2"
            @submit.prevent="
                router.get(index.url(), { q: search }, { preserveState: true })
            "
        >
            <Input
                v-model="search"
                type="search"
                maxlength="120"
                aria-label="Benutzer nach Name oder E-Mail suchen"
                placeholder="Name oder E-Mail-Adresse"
            /><Button variant="outline" aria-label="Benutzer suchen"
                ><Search class="size-4"
            /></Button>
        </form>
        <section
            class="overflow-hidden rounded-xl border bg-card"
            aria-label="Benutzerkonten"
        >
            <div class="border-b px-5 py-4 text-sm font-medium">
                {{ `${users.total} Benutzerkonten` }}
            </div>
            <p
                v-if="!users.data.length"
                class="p-5 text-sm text-muted-foreground"
            >
                Keine Benutzer gefunden.
            </p>
            <ul class="divide-y">
                <li
                    v-for="user in users.data"
                    :key="user.id"
                    class="flex flex-wrap items-center gap-3 p-5"
                    :data-user-id="user.id"
                >
                    <div class="min-w-0 flex-1">
                        <p class="font-medium break-words">
                            {{ user.name
                            }}<span
                                v-if="user.id === page.props.auth.user.id"
                                class="ml-2 text-xs text-muted-foreground"
                                >Du</span
                            >
                        </p>
                        <p class="mt-1 text-sm break-all text-muted-foreground">
                            {{ user.email }}
                        </p>
                        <div class="mt-2 flex flex-wrap gap-1">
                            <Badge
                                v-for="role in user.roles"
                                :key="role"
                                variant="secondary"
                                >{{ roles[role] || role }}</Badge
                            ><span
                                v-if="!user.roles?.length"
                                class="text-xs text-muted-foreground"
                                >Keine Verwaltungsrechte</span
                            >
                        </div>
                    </div>
                    <Badge
                        :variant="user.is_active ? 'outline' : 'secondary'"
                        >{{ user.is_active ? 'Aktiv' : 'Deaktiviert' }}</Badge
                    ><Badge v-if="!user.email_verified_at" variant="outline"
                        >E-Mail unbestätigt</Badge
                    >
                    <Button
                        v-if="
                            user.is_active &&
                            user.id !== page.props.auth.user.id
                        "
                        variant="outline"
                        size="sm"
                        :disabled="inviting === user.id"
                        :aria-label="`Zugangsmail an ${user.name} senden`"
                        @click="sendInvitation(user)"
                        ><Spinner v-if="inviting === user.id" /><MailPlus
                            v-else
                            class="size-3.5"
                        />Zugangsmail</Button
                    >
                    <Button
                        variant="outline"
                        size="sm"
                        :aria-label="`${user.name} bearbeiten`"
                        @click="edit(user)"
                        ><Pencil class="size-3.5" />Bearbeiten</Button
                    >
                </li>
            </ul>
            <div
                v-if="users.last_page > 1"
                class="flex items-center justify-between border-t p-4"
            >
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="!users.prev_page_url"
                    @click="
                        users.prev_page_url && router.get(users.prev_page_url)
                    "
                    >Zurück</Button
                ><span class="text-xs text-muted-foreground">{{
                    `Seite ${users.current_page} von ${users.last_page}`
                }}</span
                ><Button
                    variant="outline"
                    size="sm"
                    :disabled="!users.next_page_url"
                    @click="
                        users.next_page_url && router.get(users.next_page_url)
                    "
                    >Weiter</Button
                >
            </div>
        </section>
    </div>
    <Dialog :open="open" @update:open="close"
        ><DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader
                ><DialogTitle>{{
                    selected ? 'Benutzer bearbeiten' : 'Benutzer anlegen'
                }}</DialogTitle
                ><DialogDescription
                    >Anmeldung erfolgt mit E-Mail-Adresse und Passwort.
                    Berechtigungen werden aus den ausgewählten Rollen
                    kombiniert. Passwörter werden nie per E-Mail
                    versendet.</DialogDescription
                ></DialogHeader
            >
            <form
                class="space-y-4"
                data-test="user-form"
                @submit.prevent="save"
            >
                <InputError :message="form.errors.lock_version" role="alert" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label for="user-name">Name</Label
                        ><Input
                            id="user-name"
                            v-model="form.name"
                            required
                            maxlength="255"
                        /><InputError :message="form.errors.name" />
                    </div>
                    <div class="space-y-2">
                        <Label for="user-email">E-Mail-Adresse</Label
                        ><Input
                            id="user-email"
                            v-model="form.email"
                            type="email"
                            required
                            maxlength="255"
                        /><InputError :message="form.errors.email" />
                    </div>
                </div>
                <fieldset class="space-y-3 rounded-lg border p-4">
                    <legend class="px-1 text-sm font-medium">
                        Rollen & Rechte
                    </legend>
                    <label
                        v-for="(label, role) in roles"
                        :key="role"
                        class="flex items-start gap-3 rounded-md border p-3 text-sm"
                        ><input
                            v-model="form.roles"
                            :value="role"
                            type="checkbox"
                            class="mt-0.5 size-4 shrink-0 accent-primary"
                            :disabled="
                                selected?.id === page.props.auth.user.id &&
                                role === 'admin'
                            "
                        /><span
                            ><span class="font-medium">{{ label }}</span
                            ><span
                                class="mt-0.5 block text-xs text-muted-foreground"
                                >{{ descriptions[role] }}</span
                            ><span class="mt-2 flex flex-wrap gap-1">
                                <Badge
                                    v-for="area in areas[role]"
                                    :key="area"
                                    variant="outline"
                                    class="font-normal"
                                    >{{ area }}</Badge
                                >
                            </span>
                        </span></label
                    ><InputError :message="form.errors.roles" />
                </fieldset>
                <div class="grid gap-3 text-sm">
                    <label class="flex items-center gap-2"
                        ><input
                            v-model="form.is_active"
                            type="checkbox"
                            class="size-4 accent-primary"
                            :disabled="selected?.id === page.props.auth.user.id"
                        />Konto aktiv</label
                    ><label class="flex items-center gap-2"
                        ><input
                            v-model="form.verified"
                            type="checkbox"
                            class="size-4 accent-primary"
                            :disabled="selected?.id === page.props.auth.user.id"
                        />E-Mail-Adresse administrativ bestätigt</label
                    ><InputError
                        :message="form.errors.is_active || form.errors.verified"
                    />
                </div>
                <label v-if="!selected" class="flex items-start gap-2 text-sm"
                    ><input
                        v-model="form.send_invitation"
                        type="checkbox"
                        class="mt-0.5 size-4 shrink-0 accent-primary"
                        data-test="send-invitation"
                    /><span
                        ><span class="font-medium">Zugangsmail senden</span
                        ><span
                            class="mt-0.5 block text-xs text-muted-foreground"
                            >Die Person erhält eine E-Mail mit ihren Rollen und
                            einem {{ invitationHours }} Stunden gültigen Link,
                            über den sie ihr Passwort selbst festlegt.</span
                        ></span
                    ></label
                ><InputError :message="form.errors.send_invitation" />
                <fieldset class="space-y-3 rounded-lg border p-4">
                    <legend class="px-1 text-sm font-medium">
                        <KeyRound class="mr-1 inline size-3.5" />{{
                            selected || form.send_invitation
                                ? 'Passwort (optional)'
                                : 'Passwort'
                        }}
                    </legend>
                    <p class="text-xs text-muted-foreground">
                        Mindestens 12 Zeichen.
                        {{
                            selected
                                ? 'Leer lassen, um das bisherige Passwort beizubehalten.'
                                : form.send_invitation
                                  ? 'Leer lassen, damit die Person ihr Passwort über den Link selbst festlegt.'
                                  : 'Das Passwort wird nicht per E-Mail versendet.'
                        }}
                    </p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <Label for="user-password" class="sr-only"
                                >Passwort</Label
                            ><Input
                                id="user-password"
                                v-model="form.password"
                                type="password"
                                autocomplete="new-password"
                                :required="!selected && !form.send_invitation"
                                placeholder="Passwort"
                            /><InputError :message="form.errors.password" />
                        </div>
                        <div>
                            <Label
                                for="user-password-confirmation"
                                class="sr-only"
                                >Passwort wiederholen</Label
                            ><Input
                                id="user-password-confirmation"
                                v-model="form.password_confirmation"
                                type="password"
                                autocomplete="new-password"
                                :required="!selected && !form.send_invitation"
                                placeholder="Passwort wiederholen"
                            />
                        </div>
                    </div>
                </fieldset>
                <DialogFooter
                    ><Button
                        type="button"
                        variant="outline"
                        :disabled="form.processing"
                        @click="close(false)"
                        >Abbrechen</Button
                    ><Button :disabled="form.processing" data-test="save-user"
                        ><Spinner v-if="form.processing" />Speichern</Button
                    ></DialogFooter
                >
            </form>
        </DialogContent></Dialog
    >
</template>
