<script setup lang="ts">
import Image from '@tiptap/extension-image';
import TextAlign from '@tiptap/extension-text-align';
import StarterKit from '@tiptap/starter-kit';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import {
    AlignCenter,
    AlignLeft,
    AlignRight,
    Bold,
    Heading2,
    ImagePlus,
    Italic,
    Link as LinkIcon,
    List,
    ListOrdered,
    Quote,
    Redo2,
    RemoveFormatting,
    Strikethrough,
    Underline as UnderlineIcon,
    Undo2,
} from '@lucide/vue';
import { onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps<{ modelValue: string; id?: string }>();
const emit = defineEmits<{
    'update:modelValue': [value: string];
    focus: [];
    error: [message: string];
}>();
const imageInput = ref<HTMLInputElement | null>(null);
const editor = useEditor({
    content: props.modelValue,
    extensions: [
        StarterKit.configure({
            heading: { levels: [1, 2, 3] },
            link: {
                openOnClick: false,
                autolink: true,
                defaultProtocol: 'https',
            },
        }),
        Image.configure({ allowBase64: true }),
        TextAlign.configure({ types: ['heading', 'paragraph'] }),
    ],
    editorProps: {
        attributes: {
            id: props.id ?? '',
            class: 'rich-text-content',
            role: 'textbox',
            'aria-multiline': 'true',
        },
    },
    onUpdate: ({ editor: current }) =>
        emit('update:modelValue', current.getHTML()),
    onFocus: () => emit('focus'),
});

watch(
    () => props.modelValue,
    (value) => {
        if (editor.value && editor.value.getHTML() !== value)
            editor.value.commands.setContent(value, { emitUpdate: false });
    },
);

const toggleLink = () => {
    if (!editor.value) return;
    const previous = editor.value.getAttributes('link').href as
        | string
        | undefined;
    const href = window.prompt('Linkadresse', previous ?? 'https://');
    if (href === null) return;
    if (href.trim() === '')
        editor.value.chain().focus().extendMarkRange('link').unsetLink().run();
    else
        editor.value
            .chain()
            .focus()
            .extendMarkRange('link')
            .setLink({ href: href.trim() })
            .run();
};

const addImage = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    emit('error', '');
    if (!file) return;
    if (
        !['image/png', 'image/jpeg', 'image/gif', 'image/webp'].includes(
            file.type,
        )
    ) {
        emit('error', 'Erlaubt sind PNG-, JPEG-, GIF- und WebP-Bilder.');
        return;
    }
    if (file.size > 1_000_000) {
        emit('error', 'Ein eingebettetes Bild darf höchstens 1 MB groß sein.');
        return;
    }
    const reader = new FileReader();
    reader.onload = () => {
        if (typeof reader.result === 'string')
            editor.value
                ?.chain()
                .focus()
                .setImage({ src: reader.result, alt: file.name })
                .run();
    };
    reader.readAsDataURL(file);
};

const insertText = (text: string) =>
    editor.value?.chain().focus().insertContent(text).run();
defineExpose({ insertText });
onBeforeUnmount(() => editor.value?.destroy());
</script>

<template>
    <div
        class="overflow-hidden rounded-md border border-input bg-background shadow-xs focus-within:border-ring focus-within:ring-2 focus-within:ring-ring/30"
    >
        <div
            class="flex flex-wrap gap-1 border-b bg-muted/40 p-2"
            role="toolbar"
            aria-label="Text formatieren"
        >
            <button
                type="button"
                title="Fett"
                aria-label="Fett"
                :class="{ active: editor?.isActive('bold') }"
                @click="editor?.chain().focus().toggleBold().run()"
            >
                <Bold />
            </button>
            <button
                type="button"
                title="Kursiv"
                aria-label="Kursiv"
                :class="{ active: editor?.isActive('italic') }"
                @click="editor?.chain().focus().toggleItalic().run()"
            >
                <Italic />
            </button>
            <button
                type="button"
                title="Unterstrichen"
                aria-label="Unterstrichen"
                :class="{ active: editor?.isActive('underline') }"
                @click="editor?.chain().focus().toggleUnderline().run()"
            >
                <UnderlineIcon />
            </button>
            <button
                type="button"
                title="Durchgestrichen"
                aria-label="Durchgestrichen"
                :class="{ active: editor?.isActive('strike') }"
                @click="editor?.chain().focus().toggleStrike().run()"
            >
                <Strikethrough />
            </button>
            <span class="mx-1 w-px bg-border"></span>
            <button
                type="button"
                title="Überschrift"
                aria-label="Überschrift"
                :class="{ active: editor?.isActive('heading', { level: 2 }) }"
                @click="
                    editor?.chain().focus().toggleHeading({ level: 2 }).run()
                "
            >
                <Heading2 />
            </button>
            <button
                type="button"
                title="Aufzählung"
                aria-label="Aufzählung"
                :class="{ active: editor?.isActive('bulletList') }"
                @click="editor?.chain().focus().toggleBulletList().run()"
            >
                <List />
            </button>
            <button
                type="button"
                title="Nummerierte Liste"
                aria-label="Nummerierte Liste"
                :class="{ active: editor?.isActive('orderedList') }"
                @click="editor?.chain().focus().toggleOrderedList().run()"
            >
                <ListOrdered />
            </button>
            <button
                type="button"
                title="Zitat"
                aria-label="Zitat"
                :class="{ active: editor?.isActive('blockquote') }"
                @click="editor?.chain().focus().toggleBlockquote().run()"
            >
                <Quote />
            </button>
            <span class="mx-1 w-px bg-border"></span>
            <button
                type="button"
                title="Link"
                aria-label="Link"
                :class="{ active: editor?.isActive('link') }"
                @click="toggleLink"
            >
                <LinkIcon />
            </button>
            <button
                type="button"
                title="Bild einfügen"
                aria-label="Bild einfügen"
                @click="imageInput?.click()"
            >
                <ImagePlus />
            </button>
            <input
                ref="imageInput"
                type="file"
                accept="image/png,image/jpeg,image/gif,image/webp"
                class="hidden"
                @change="addImage"
            />
            <span class="mx-1 w-px bg-border"></span>
            <button
                type="button"
                title="Linksbündig"
                aria-label="Linksbündig"
                :class="{ active: editor?.isActive({ textAlign: 'left' }) }"
                @click="editor?.chain().focus().setTextAlign('left').run()"
            >
                <AlignLeft />
            </button>
            <button
                type="button"
                title="Zentriert"
                aria-label="Zentriert"
                :class="{ active: editor?.isActive({ textAlign: 'center' }) }"
                @click="editor?.chain().focus().setTextAlign('center').run()"
            >
                <AlignCenter />
            </button>
            <button
                type="button"
                title="Rechtsbündig"
                aria-label="Rechtsbündig"
                :class="{ active: editor?.isActive({ textAlign: 'right' }) }"
                @click="editor?.chain().focus().setTextAlign('right').run()"
            >
                <AlignRight />
            </button>
            <button
                type="button"
                title="Formatierung entfernen"
                aria-label="Formatierung entfernen"
                @click="
                    editor?.chain().focus().unsetAllMarks().clearNodes().run()
                "
            >
                <RemoveFormatting />
            </button>
            <span class="mx-1 w-px bg-border"></span>
            <button
                type="button"
                title="Rückgängig"
                aria-label="Rückgängig"
                :disabled="!editor?.can().undo()"
                @click="editor?.chain().focus().undo().run()"
            >
                <Undo2 />
            </button>
            <button
                type="button"
                title="Wiederholen"
                aria-label="Wiederholen"
                :disabled="!editor?.can().redo()"
                @click="editor?.chain().focus().redo().run()"
            >
                <Redo2 />
            </button>
        </div>
        <EditorContent :editor="editor" />
    </div>
</template>

<style scoped>
button {
    display: inline-flex;
    width: 2rem;
    height: 2rem;
    align-items: center;
    justify-content: center;
    border-radius: 0.375rem;
    color: var(--muted-foreground);
}
button:hover:not(:disabled),
button.active {
    background: var(--accent);
    color: var(--accent-foreground);
}
button:disabled {
    opacity: 0.35;
}
button :deep(svg) {
    width: 1rem;
    height: 1rem;
}
:deep(.rich-text-content) {
    min-height: 18rem;
    padding: 0.75rem;
    font-size: 0.875rem;
    line-height: 1.6;
    outline: none;
}
:deep(.rich-text-content p) {
    margin: 0 0 0.75rem;
}
:deep(.rich-text-content h2) {
    margin: 1rem 0 0.5rem;
    font-size: 1.25rem;
    font-weight: 600;
}
:deep(.rich-text-content ul) {
    margin: 0.5rem 0 0.75rem 1.5rem;
    list-style: disc;
}
:deep(.rich-text-content ol) {
    margin: 0.5rem 0 0.75rem 1.5rem;
    list-style: decimal;
}
:deep(.rich-text-content blockquote) {
    margin: 0.75rem 0;
    border-left: 3px solid var(--border);
    padding-left: 0.75rem;
    color: var(--muted-foreground);
}
:deep(.rich-text-content a) {
    color: var(--primary);
    text-decoration: underline;
}
:deep(.rich-text-content img) {
    display: block;
    max-width: 100%;
    height: auto;
    margin: 0.75rem 0;
}
</style>
