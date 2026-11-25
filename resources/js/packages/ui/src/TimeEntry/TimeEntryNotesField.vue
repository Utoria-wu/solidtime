<script setup lang="ts">
import { ref, useId, watchPostEffect } from 'vue';
import { Field, FieldLabel } from '../field';
import { noteColors, renderNotes, serializeNotes } from '../utils/notes';

withDefaults(defineProps<{ label?: string }>(), { label: 'Notes' });
const notes = defineModel<string | null>();
const editor = ref<HTMLDivElement | null>(null);
const inputId = useId();
const tooLong = ref(false);
let previousValue: string | null | undefined;
let selection: Range | undefined;

watchPostEffect(() => {
    const value = notes.value;
    if (editor.value && (previousValue !== value || editor.value.dataset.ready !== 'true')) {
        previousValue = value;
        editor.value.innerHTML = renderNotes(value);
        editor.value.dataset.ready = 'true';
        selection = undefined;
        tooLong.value = false;
    }
});

function rememberSelection() {
    const current = window.getSelection();
    if (
        current?.rangeCount &&
        editor.value?.contains(current.getRangeAt(0).commonAncestorContainer)
    )
        selection = current.getRangeAt(0).cloneRange();
}

function syncNotes() {
    if (!editor.value) return;
    const value = serializeNotes(editor.value);
    tooLong.value = value.length > 10000;
    if (tooLong.value) {
        editor.value.innerHTML = renderNotes(previousValue);
        selection = undefined;
        return;
    }
    previousValue = value || null;
    notes.value = previousValue;
    rememberSelection();
}

function format(command: string, value?: string) {
    const target = editor.value;
    if (!target) return;
    target.focus();
    const current = window.getSelection();
    if (selection && target.contains(selection.commonAncestorContainer)) {
        current?.removeAllRanges();
        current?.addRange(selection);
    }
    document.execCommand('styleWithCSS', false, 'false');
    document.execCommand(command, false, value);
    syncNotes();
}

function resetColor() {
    if (editor.value) format('foreColor', getComputedStyle(editor.value).color);
}

function paste(event: ClipboardEvent) {
    const text = event.clipboardData?.getData('text/plain');
    if (!text) return;
    const escaped = document.createElement('div');
    escaped.textContent = text;
    format('insertHTML', renderNotes(escaped.innerHTML));
}
</script>

<template>
    <Field>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <FieldLabel :id="inputId + '-label'">{{ label }}</FieldLabel>
            <div role="group" aria-label="Note formatting" class="flex items-center gap-1">
                <button
                    type="button"
                    aria-label="Bold"
                    title="Bold"
                    class="format-button font-bold"
                    @mousedown.prevent
                    @click="format('bold')">
                    B
                </button>
                <button
                    type="button"
                    aria-label="Italic"
                    title="Italic"
                    class="format-button italic"
                    @mousedown.prevent
                    @click="format('italic')">
                    I
                </button>
                <button
                    type="button"
                    aria-label="Bulleted list"
                    title="Bulleted list"
                    class="format-button"
                    @mousedown.prevent
                    @click="format('insertUnorderedList')">
                    ≡
                </button>
                <span
                    class="mx-1 h-4 border-l border-default-background-separator"
                    aria-hidden="true" />
                <button
                    type="button"
                    aria-label="Default text color"
                    title="Default text color"
                    class="format-button"
                    @mousedown.prevent
                    @click="resetColor">
                    A
                </button>
                <button
                    v-for="color in noteColors"
                    :key="color.hex"
                    type="button"
                    :aria-label="color.label + ' text'"
                    :title="color.label + ' text'"
                    class="format-button"
                    @mousedown.prevent
                    @click="format('foreColor', color.hex)">
                    <span
                        class="size-3 rounded-full"
                        :style="{ backgroundColor: color.hex }"
                        aria-hidden="true" />
                </button>
            </div>
        </div>
        <div
            ref="editor"
            role="textbox"
            aria-multiline="true"
            :aria-labelledby="inputId + '-label'"
            contenteditable="true"
            class="notes-editor min-h-36 max-h-80 overflow-y-auto rounded-md border border-input-border bg-input-background p-3 text-sm text-text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            @input="syncNotes"
            @mouseup="rememberSelection"
            @keyup="rememberSelection"
            @paste.prevent="paste"
            @drop.prevent />
        <p v-if="tooLong" role="alert" class="text-xs text-destructive">The note is too long.</p>
    </Field>
</template>

<style scoped>
.format-button {
    display: inline-flex;
    width: 1.5rem;
    height: 1.5rem;
    align-items: center;
    justify-content: center;
    border-radius: 0.25rem;
}
.format-button:hover,
.format-button:focus-visible {
    background: var(--theme-color-tertiary);
}
.notes-editor {
    overflow-wrap: anywhere;
}

.notes-editor :deep(h1),
.notes-editor :deep(h2),
.notes-editor :deep(h3) {
    margin-bottom: 0.75rem;
    font-size: 1rem;
    font-weight: 600;
}
.notes-editor :deep(p),
.notes-editor :deep(ul),
.notes-editor :deep(ol) {
    margin-bottom: 0.75rem;
    line-height: 1.75;
}
.notes-editor :deep(ul) {
    padding-left: 1.25rem;
    list-style-type: disc;
}
.notes-editor :deep(ol) {
    padding-left: 1.25rem;
    list-style-type: decimal;
}
.notes-editor :deep(ul:has(> li > input[type='checkbox'])) {
    padding-left: 0;
    list-style: none;
}
.notes-editor :deep(input[type='checkbox']) {
    margin-right: 0.5rem;
}
.notes-editor :deep(a) {
    text-decoration: underline;
}
.notes-editor :deep(code) {
    font-family: monospace;
}
.notes-editor :deep(pre) {
    overflow-x: auto;
    padding: 0.75rem;
}
.notes-editor :deep(blockquote) {
    border-left: 2px solid currentColor;
    padding-left: 0.75rem;
}
.notes-editor :deep(:last-child) {
    margin-bottom: 0;
}
</style>
