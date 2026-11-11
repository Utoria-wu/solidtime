<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import { marked } from 'marked';
import DOMPurify from 'dompurify';
import TextareaInput from '../Input/TextareaInput.vue';
import TabBar from '../TabBar/TabBar.vue';
import TabBarItem from '../TabBar/TabBarItem.vue';
import { Field, FieldLabel } from '../field';

const notes = defineModel<string | null>();
const inputId = useId();
const draft = computed({
    get: () => notes.value ?? '',
    set: (value: string | number) => {
        notes.value = String(value);
    },
});
const mode = ref('write');
const renderedNotes = computed(() =>
    DOMPurify.sanitize(marked.parse(notes.value ?? '', { async: false, gfm: true, breaks: true }), {
        USE_PROFILES: { html: true },
        FORBID_TAGS: ['img', 'style', 'form', 'textarea', 'button', 'select'],
        FORBID_ATTR: ['style'],
    })
);
</script>

<template>
    <Field>
        <div class="flex items-center justify-between gap-4">
            <FieldLabel :for="inputId">Notes</FieldLabel>
            <div class="shrink-0">
                <TabBar v-model="mode" default-value="write">
                    <TabBarItem value="write">Write</TabBarItem>
                    <TabBarItem value="preview">Preview</TabBarItem>
                </TabBar>
            </div>
        </div>
        <TextareaInput
            v-if="mode === 'write'"
            :id="inputId"
            v-model="draft"
            :rows="7"
            maxlength="10000"
            class="w-full font-mono text-sm leading-6"
            autocomplete="off"
            placeholder="Add details, a checklist, or links…" />
        <div
            v-else
            role="region"
            aria-label="Notes preview"
            class="notes-preview min-h-48 rounded-md border border-input-border bg-input-background p-3 text-sm text-text-primary">
            <div v-if="notes?.trim()" v-html="renderedNotes"></div>
            <p v-else class="text-text-tertiary">Nothing to preview yet.</p>
        </div>
        <p class="text-xs text-text-tertiary">
            Markdown supported · Headings, checklists, links, and more.
        </p>
    </Field>
</template>

<style scoped>
.notes-preview :deep(h1),
.notes-preview :deep(h2),
.notes-preview :deep(h3) {
    margin-bottom: 0.75rem;
    font-size: 1rem;
    font-weight: 600;
}
.notes-preview :deep(p),
.notes-preview :deep(ul),
.notes-preview :deep(ol) {
    margin-bottom: 0.75rem;
    line-height: 1.75;
}
.notes-preview :deep(ul) {
    padding-left: 1.25rem;
    list-style-type: disc;
}
.notes-preview :deep(ol) {
    padding-left: 1.25rem;
    list-style-type: decimal;
}
.notes-preview :deep(ul:has(> li > input[type='checkbox'])) {
    padding-left: 0;
    list-style: none;
}
.notes-preview :deep(input[type='checkbox']) {
    margin-right: 0.5rem;
}
.notes-preview :deep(a) {
    text-decoration: underline;
}
.notes-preview :deep(code) {
    font-family: monospace;
}
.notes-preview :deep(pre) {
    overflow-x: auto;
    padding: 0.75rem;
}
.notes-preview :deep(blockquote) {
    border-left: 2px solid currentColor;
    padding-left: 0.75rem;
}
.notes-preview :deep(:last-child) {
    margin-bottom: 0;
}
</style>
