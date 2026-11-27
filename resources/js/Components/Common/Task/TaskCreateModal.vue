<script setup lang="ts">
import TextInput from '@/packages/ui/src/Input/TextInput.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import { ref, useId, watch } from 'vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import { useFocus } from '@vueuse/core';
import { useTasksStore } from '@/utils/useTasks';
import ProjectDropdown from '@/Components/Common/Project/ProjectDropdown.vue';
import TimeEntryNotesField from '@/packages/ui/src/TimeEntry/TimeEntryNotesField.vue';
import { getLocalizedDayJs } from '@/packages/ui/src/utils/time';
import TaskCategoryField from './TaskCategoryField.vue';
import TaskStageField from './TaskStageField.vue';
import TaskDatesField from './TaskDatesField.vue';
import EstimatedTimeSection from '@/packages/ui/src/EstimatedTimeSection.vue';
import { isAllowedToPerformPremiumAction } from '@/utils/billing';
import { Field, FieldGroup, FieldLabel } from '@/packages/ui/src/field';
import { Button } from '@/packages/ui/src/Buttons';
import { ChevronDown } from '@lucide/vue';
import { FolderIcon, LinkIcon, XMarkIcon } from '@heroicons/vue/20/solid';
import RelatedTaskPicker from './RelatedTaskPicker.vue';
import { taskRelationshipKinds, type TaskRelationshipKind } from '@/utils/useTaskRelationships';

const { createTask } = useTasksStore();
const show = defineModel<boolean>('show', { default: false });
const saving = ref(false);

const taskName = ref('');
const categoryId = ref<string | null>(null);
const stage = ref('');
const taskNotes = ref<string | null>(null);
const showRelationship = ref(false);
const relatedTaskId = ref<string | null>(null);
const kind = ref<TaskRelationshipKind>('fixes');
const kindId = useId();
const taskDates = ref<{ start_date: string | null; due_date: string | null }>({
    start_date: null,
    due_date: null,
});

watch(
    show,
    (open) => {
        if (open) {
            taskNotes.value = null;
            categoryId.value = null;
            stage.value = '';
            clearRelationship();
            const today = getLocalizedDayJs().format('YYYY-MM-DD');
            taskDates.value = { start_date: today, due_date: today };
        }
    },
    { immediate: true }
);
const estimatedTime = ref<number | null>(null);

const props = defineProps<{
    projectId: string;
}>();

const taskProjectId = ref<string>(props.projectId);

watch(
    () => props.projectId,
    (value) => {
        taskProjectId.value = value;
    }
);

async function submit() {
    if (saving.value || (showRelationship.value && !relatedTaskId.value)) return;
    saving.value = true;
    try {
        const result = await createTask({
            name: taskName.value,
            ...taskDates.value,
            markdown_notes: taskNotes.value,
            category_id: categoryId.value,
            stage: stage.value.trim() || null,
            project_id: taskProjectId.value,
            estimated_time: estimatedTime.value,
            ...(showRelationship.value && relatedTaskId.value
                ? { relationship: { related_task_id: relatedTaskId.value, kind: kind.value } }
                : {}),
        });
        if (result) {
            show.value = false;
            taskName.value = '';
        }
    } finally {
        saving.value = false;
    }
}

function clearRelationship() {
    showRelationship.value = false;
    relatedTaskId.value = null;
    kind.value = 'fixes';
}

const taskNameInput = ref<HTMLInputElement | null>(null);

useFocus(taskNameInput, { initialValue: true });
</script>

<template>
    <DialogModal closeable :show="show" @close="show = false" @submit="submit">
        <template #title>
            <div class="flex space-x-2">
                <span> Create Task </span>
            </div>
        </template>

        <template #content>
            <FieldGroup>
                <Field class="w-full">
                    <FieldLabel for="taskName">Task name</FieldLabel>
                    <TextInput
                        id="taskName"
                        ref="taskNameInput"
                        v-model="taskName"
                        type="text"
                        placeholder="Task Name"
                        class="block w-full"
                        required
                        autocomplete="off"
                        @keydown.enter="submit()" />
                </Field>
                <Field class="w-auto">
                    <FieldLabel :icon="FolderIcon" for="project">Project</FieldLabel>
                    <ProjectDropdown v-model="taskProjectId">
                        <template #trigger="{ selectedProjectName, selectedProjectColor }">
                            <Button variant="input" class="w-full justify-between">
                                <span class="flex items-center gap-2 truncate">
                                    <span
                                        :style="{ backgroundColor: selectedProjectColor }"
                                        class="w-3 h-3 rounded-full shrink-0"></span>
                                    <span class="truncate">{{ selectedProjectName }}</span>
                                </span>
                                <ChevronDown class="w-4 h-4 text-icon-default" />
                            </Button>
                        </template>
                    </ProjectDropdown>
                </Field>
                <div class="grid grid-cols-1 items-start gap-4 sm:grid-cols-2">
                    <TaskDatesField v-model="taskDates" />
                    <EstimatedTimeSection
                        v-if="isAllowedToPerformPremiumAction()"
                        v-model="estimatedTime"
                        @submit="submit()"></EstimatedTimeSection>
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <TaskCategoryField v-model="categoryId" />
                    <TaskStageField v-model="stage" :project-id="taskProjectId" />
                </div>
                <div v-if="showRelationship" class="grid gap-2">
                    <div class="flex items-center justify-between gap-2">
                        <span
                            class="inline-flex items-center gap-2 text-sm font-medium text-text-primary">
                            <LinkIcon class="size-4 text-icon-default" aria-hidden="true" />
                            Link task
                        </span>
                        <button
                            type="button"
                            aria-label="Remove task link"
                            class="rounded-md p-1.5 text-text-secondary hover:bg-tertiary"
                            @click="clearRelationship">
                            <XMarkIcon class="size-4" aria-hidden="true" />
                        </button>
                    </div>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Field>
                            <FieldLabel :for="kindId">Relationship</FieldLabel>
                            <select
                                :id="kindId"
                                v-model="kind"
                                class="h-9 w-full rounded-md border border-input-border bg-input-background px-3 text-sm text-text-primary shadow-sm focus-visible:ring-2 focus-visible:ring-ring">
                                <option
                                    v-for="(item, key) in taskRelationshipKinds"
                                    :key="key"
                                    :value="key">
                                    {{ item.label }}
                                </option>
                            </select>
                        </Field>
                        <RelatedTaskPicker
                            v-model="relatedTaskId"
                            :project-id="taskProjectId"
                            :disabled="saving" />
                    </div>
                </div>
                <button
                    v-else
                    type="button"
                    class="inline-flex items-center gap-2 self-start rounded-md px-2 py-1.5 text-sm text-text-secondary hover:bg-tertiary"
                    @click="showRelationship = true">
                    <LinkIcon class="size-4 text-icon-default" aria-hidden="true" />
                    Link task
                </button>
                <TimeEntryNotesField v-model="taskNotes" label="Task notes" />
            </FieldGroup>
        </template>
        <template #footer>
            <SecondaryButton @click="show = false"> Cancel </SecondaryButton>
            <PrimaryButton
                class="ms-3"
                :class="{ 'opacity-25': saving }"
                :disabled="saving || (showRelationship && !relatedTaskId)"
                @click="submit">
                Create Task
            </PrimaryButton>
        </template>
    </DialogModal>
</template>

<style scoped></style>
