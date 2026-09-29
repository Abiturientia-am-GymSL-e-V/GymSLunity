<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Download, Mail, Search } from '@lucide/vue';
import Frame from '@/components/selfservice/Frame.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime, formatNumber } from '@/lib/format';
import { attachment, html, index } from '@/routes/demo/mailbox';

type MailSummary = {
    id: number;
    subject: string;
    to: string;
    createdAt: string | null;
};

const props = defineProps<{
    recipient: string;
    mails: MailSummary[];
    selected: {
        id: number;
        subject: string;
        sender: string;
        to: string;
        cc: string;
        bcc: string;
        createdAt: string | null;
        hasHtml: boolean;
        text: string | null;
        attachments: { name: string; size: number; available: boolean }[];
    } | null;
}>();

const mailHref = (id: number) =>
    index.url({
        query: {
            ...(props.recipient ? { empfaenger: props.recipient } : {}),
            mail: id,
        },
    });
</script>

<template>
    <Head title="Demo-Postfach" />
    <Frame>
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Demo-Postfach</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Die Demo verschickt keine E-Mails. Alle Nachrichten landen hier,
                auch die Anmeldelinks für den Mitgliederbereich.
            </p>
        </header>

        <Form
            :action="index.url()"
            method="get"
            class="grid gap-3 rounded-xl border bg-card p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
        >
            <div class="min-w-0 space-y-2">
                <Label for="mailbox-recipient">Empfänger</Label>
                <Input
                    id="mailbox-recipient"
                    name="empfaenger"
                    type="search"
                    :default-value="recipient"
                    placeholder="z. B. mitglied@example.org"
                />
            </div>
            <Button type="submit" variant="outline">
                <Search class="size-4" />
                Filtern
            </Button>
        </Form>

        <StatusAlert
            v-if="mails.length === 0"
            type="info"
            title="Keine Nachrichten"
        >
            {{
                recipient
                    ? 'Für diesen Empfänger liegen keine Nachrichten vor.'
                    : 'Seit der letzten Zurücksetzung wurden keine E-Mails versendet.'
            }}
        </StatusAlert>

        <div
            v-else
            class="grid gap-6 lg:grid-cols-[minmax(0,20rem)_minmax(0,1fr)]"
        >
            <nav
                aria-label="Nachrichten"
                class="max-h-[75vh] min-w-0 overflow-y-auto rounded-xl border bg-card"
            >
                <ul class="divide-y">
                    <li v-for="mail in mails" :key="mail.id">
                        <Link
                            :href="mailHref(mail.id)"
                            preserve-scroll
                            :aria-current="
                                selected?.id === mail.id ? 'page' : undefined
                            "
                            class="block space-y-1 px-4 py-3 text-sm hover:bg-muted focus-visible:bg-muted focus-visible:outline-none aria-[current=page]:bg-muted"
                        >
                            <span class="block truncate font-medium">
                                {{ mail.subject || '(ohne Betreff)' }}
                            </span>
                            <span class="block truncate text-muted-foreground">
                                {{ mail.to }}
                            </span>
                            <span
                                v-if="mail.createdAt"
                                class="block text-xs text-muted-foreground"
                            >
                                {{ formatDateTime(mail.createdAt) }}
                            </span>
                        </Link>
                    </li>
                </ul>
            </nav>

            <article
                v-if="selected"
                class="min-w-0 rounded-xl border bg-card"
                :aria-label="selected.subject"
            >
                <header class="space-y-2 border-b px-5 py-4">
                    <h2 class="text-lg font-semibold break-words">
                        {{ selected.subject || '(ohne Betreff)' }}
                    </h2>
                    <dl
                        class="grid gap-x-3 gap-y-1 text-sm sm:grid-cols-[auto_minmax(0,1fr)]"
                    >
                        <dt class="text-muted-foreground">Von</dt>
                        <dd class="break-words">{{ selected.sender }}</dd>
                        <dt class="text-muted-foreground">An</dt>
                        <dd class="break-words">{{ selected.to }}</dd>
                        <template v-if="selected.cc">
                            <dt class="text-muted-foreground">Cc</dt>
                            <dd class="break-words">{{ selected.cc }}</dd>
                        </template>
                        <template v-if="selected.bcc">
                            <dt class="text-muted-foreground">Bcc</dt>
                            <dd class="break-words">{{ selected.bcc }}</dd>
                        </template>
                        <template v-if="selected.createdAt">
                            <dt class="text-muted-foreground">Gesendet</dt>
                            <dd>{{ formatDateTime(selected.createdAt) }}</dd>
                        </template>
                    </dl>
                    <ul
                        v-if="selected.attachments.length"
                        class="flex flex-wrap gap-2 pt-1"
                    >
                        <li
                            v-for="(file, position) in selected.attachments"
                            :key="position"
                        >
                            <Button
                                v-if="file.available"
                                as-child
                                variant="outline"
                                size="sm"
                            >
                                <a
                                    :href="
                                        attachment.url({
                                            mail: selected.id,
                                            index: position,
                                        })
                                    "
                                    download
                                >
                                    <Download class="size-4" />
                                    {{ file.name }}
                                </a>
                            </Button>
                            <span v-else class="text-sm text-muted-foreground">
                                {{ file.name }} ({{
                                    formatNumber(Math.round(file.size / 1024))
                                }}
                                KB, zu groß für die Demo)
                            </span>
                        </li>
                    </ul>
                </header>
                <!-- The mail HTML always keeps its own light design. -->
                <iframe
                    v-if="selected.hasHtml"
                    :key="selected.id"
                    :src="html.url(selected.id)"
                    sandbox="allow-popups allow-popups-to-escape-sandbox"
                    referrerpolicy="no-referrer"
                    :title="`Inhalt: ${selected.subject}`"
                    class="block h-[70vh] w-full rounded-b-xl bg-white"
                />
                <pre
                    v-else
                    class="p-5 font-sans text-sm break-words whitespace-pre-wrap"
                    >{{ selected.text }}</pre>
            </article>
            <div
                v-else
                class="flex min-h-40 items-center justify-center gap-2 rounded-xl border bg-card p-5 text-sm text-muted-foreground"
            >
                <Mail class="size-4" aria-hidden="true" />
                Nachricht aus der Liste auswählen.
            </div>
        </div>
    </Frame>
</template>
