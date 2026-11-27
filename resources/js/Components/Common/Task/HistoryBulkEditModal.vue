<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import TextInput from '@/packages/ui/src/Input/TextInput.vue';
import type { HistoryClassification, TaskCategory } from '@/utils/useTaskHistory';

const show = defineModel<boolean>('show', { default: false });
const props = defineProps<{
    taskIds: string[];
    timeEntryIds: string[];
    categories: TaskCategory[];
    stages: string[];
    save: (tasks: string[], entries: string[], changes: HistoryClassification) => Promise<boolean>;
}>();
const emit = defineEmits<{ saved: [] }>();
const categoryId = ref<string | null>(null);
const stage = ref('');
const changeCategory = ref(false);
const changeStage = ref(false);
const saving = ref(false);
const stageSuggestions = useId();
const canSave = computed(
    () =>
        !saving.value &&
        (props.taskIds.length > 0 || props.timeEntryIds.length > 0) &&
        (changeCategory.value || changeStage.value)
);

watch(show, (open) => {
    if (!open) return;
    categoryId.value = null;
    stage.value = '';
    changeCategory.value = false;
    changeStage.value = false;
});

async function submit() {
    if (!canSave.value) return;
    const changes: HistoryClassification = {};
    if (changeCategory.value) changes.category_id = categoryId.value;
    if (changeStage.value) changes.stage = stage.value.trim() || null;
    saving.value = true;
    try {
        if (await props.save(props.taskIds, props.timeEntryIds, changes)) {
            show.value = false;
            emit('saved');
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <DialogModal
        :show="show"
        :closeable="!saving"
        max-width="md"
        @close="show = false"
        @submit="submit">
        <template #title>Edit selected events</template>
        <template #content>
            <p class="mb-5 tabular-nums text-text-secondary">
                {{ taskIds.length }} {{ taskIds.length === 1 ? 'task' : 'tasks' }}
                <span class="px-1" aria-hidden="true">·</span>
                {{ timeEntryIds.length }}
                {{ timeEntryIds.length === 1 ? 'Calendar entry' : 'Calendar entries' }}
            </p>
            <div class="grid gap-4">
                <div class="grid gap-2">
                    <label class="inline-flex items-center gap-2 text-text-primary">
                        <input
                            v-model="changeCategory"
                            type="checkbox"
                            :disabled="saving"
                            class="size-4 rounded border-input-border bg-card-background text-accent-600 focus:ring-0 focus-visible:ring-2 focus-visible:ring-ring" />
                        Change category
                    </label>
                    <select
                        v-model="categoryId"
                        aria-label="Category"
                        :disabled="saving || !changeCategory"
                        class="h-9 w-full rounded-md border border-input-border bg-default-background text-sm text-text-primary disabled:opacity-50">
                        <option :value="null">Uncategorized</option>
                        <option
                            v-for="category in categories.filter((item) => !item.is_archived)"
                            :key="category.id"
                            :value="category.id">
                            {{ category.name }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <label class="inline-flex items-center gap-2 text-text-primary">
                        <input
                            v-model="changeStage"
                            type="checkbox"
                            :disabled="saving"
                            class="size-4 rounded border-input-border bg-card-background text-accent-600 focus:ring-0 focus-visible:ring-2 focus-visible:ring-ring" />
                        Change stage
                    </label>
                    <TextInput
                        v-model="stage"
                        aria-label="Stage"
                        :list="stageSuggestions"
                        :disabled="saving || !changeStage"
                        class="w-full disabled:opacity-50"
                        maxlength="100"
                        placeholder="No stage"
                        autocomplete="off" />
                    <datalist :id="stageSuggestions">
                        <option v-for="item in stages" :key="item" :value="item" />
                    </datalist>
                </div>
            </div>
            <p v-if="taskIds.length" class="mt-4 text-xs text-text-secondary">
                Task changes apply to all History entries that use the task's classification.
            </p>
        </template>
        <template #footer>
            <SecondaryButton :disabled="saving" @click="show = false">Cancel</SecondaryButton>
            <PrimaryButton
                type="button"
                class="ms-2 disabled:opacity-50"
                :loading="saving"
                :disabled="!canSave"
                @click="submit">
                Apply changes
            </PrimaryButton>
        </template>
    </DialogModal>
</template>
