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
const categoryId = ref<string | null>('');
const stage = ref('');
const clearStage = ref(false);
const saving = ref(false);
const stageSuggestions = useId();
const canSave = computed(
    () =>
        !saving.value &&
        (props.taskIds.length > 0 || props.timeEntryIds.length > 0) &&
        (categoryId.value !== '' || clearStage.value || Boolean(stage.value.trim()))
);

watch(show, (open) => {
    if (!open) return;
    categoryId.value = '';
    stage.value = '';
    clearStage.value = false;
});

function toggleClearStage() {
    stage.value = '';
    clearStage.value = !clearStage.value;
}

async function submit() {
    if (!canSave.value) return;
    const changes: HistoryClassification = {};
    if (categoryId.value !== '') changes.category_id = categoryId.value;
    if (clearStage.value) changes.stage = null;
    else if (stage.value.trim()) changes.stage = stage.value.trim();
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
                <label class="grid gap-1.5 font-medium text-text-primary">
                    Category
                    <select
                        v-model="categoryId"
                        aria-label="Category"
                        :disabled="saving"
                        class="h-9 w-full rounded-md border border-input-border bg-input-background text-sm font-normal text-text-primary focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50">
                        <option value="">Keep current</option>
                        <option :value="null">Uncategorized</option>
                        <option
                            v-for="category in categories.filter((item) => !item.is_archived)"
                            :key="category.id"
                            :value="category.id">
                            {{ category.name }}
                        </option>
                    </select>
                </label>
                <div class="grid gap-1.5">
                    <div class="flex items-center justify-between gap-2">
                        <label
                            :for="stageSuggestions + '-input'"
                            class="font-medium text-text-primary"
                            >Stage</label
                        >
                        <button
                            type="button"
                            :disabled="saving"
                            class="text-xs text-text-secondary underline-offset-2 hover:underline disabled:opacity-50"
                            @click="toggleClearStage">
                            {{ clearStage ? 'Undo clear' : 'Clear stage' }}
                        </button>
                    </div>
                    <TextInput
                        :id="stageSuggestions + '-input'"
                        v-model="stage"
                        :list="stageSuggestions"
                        :disabled="saving || clearStage"
                        class="w-full disabled:opacity-50"
                        maxlength="100"
                        :placeholder="clearStage ? 'No stage' : 'Keep current'"
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
