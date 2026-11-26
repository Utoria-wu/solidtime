<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import { ChevronDownIcon, XMarkIcon } from '@heroicons/vue/20/solid';
import { ComboboxContent, ComboboxInput, ComboboxItem, ComboboxRoot } from 'reka-ui';
import type { Task } from '@/packages/api/src';
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import Dropdown from '@/packages/ui/src/Input/Dropdown.vue';
import { Field, FieldGroup, FieldLabel } from '@/packages/ui/src/field';
import { useTasksQuery } from '@/utils/useTasksQuery';
import {
    taskRelationshipKinds,
    useTaskRelationships,
    type TaskRelationshipKind,
} from '@/utils/useTaskRelationships';

const props = defineProps<{ task: Task }>();
const show = defineModel<boolean>('show', { required: true });
const { tasks } = useTasksQuery();
const { relationships, isPending, isError, refetch, link, unlink } = useTaskRelationships(
    props.task.project_id
);
const relationId = useId();
const taskId = useId();
const statusId = useId();
const kind = ref<TaskRelationshipKind>('fixes');
const relatedTaskId = ref<string | null>(null);
const pickerOpen = ref(false);
const search = ref('');
const busy = ref(false);

const projectTasks = computed(() =>
    tasks.value.filter((task) => task.project_id === props.task.project_id)
);
const taskNames = computed(() => new Map(projectTasks.value.map((task) => [task.id, task.name])));
const existingLinks = computed(() =>
    relationships.value.filter(
        (edge) => edge.task_id === props.task.id && taskNames.value.has(edge.related_task_id)
    )
);
const shownTasks = computed(() => {
    const linked = new Set(existingLinks.value.map((edge) => edge.related_task_id));
    const filter = search.value.trim().toLocaleLowerCase();
    return projectTasks.value
        .filter(
            (task) =>
                task.id !== props.task.id &&
                !linked.has(task.id) &&
                task.name.toLocaleLowerCase().includes(filter)
        )
        .sort((left, right) => left.name.localeCompare(right.name));
});
const selectedTask = computed(() =>
    relatedTaskId.value ? taskNames.value.get(relatedTaskId.value) : undefined
);

function selectTask(id: string | null) {
    if (!id) return;
    relatedTaskId.value = id;
    pickerOpen.value = false;
}

async function submit() {
    if (
        busy.value ||
        isPending.value ||
        isError.value ||
        !selectedTask.value ||
        !relatedTaskId.value
    )
        return;
    busy.value = true;
    try {
        if (
            await link({
                task_id: props.task.id,
                related_task_id: relatedTaskId.value,
                kind: kind.value,
            })
        )
            show.value = false;
    } finally {
        busy.value = false;
    }
}

async function remove(id: string) {
    if (busy.value) return;
    busy.value = true;
    try {
        await unlink(id);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <DialogModal
        :show="show"
        max-width="lg"
        :closeable="!busy"
        @close="show = false"
        @submit="submit">
        <template #title>Link task</template>
        <template #content>
            <FieldGroup>
                <div>
                    <p class="text-xs text-text-secondary">From task</p>
                    <p class="mt-0.5 break-words font-medium text-text-primary">{{ task.name }}</p>
                </div>

                <Field>
                    <FieldLabel :for="relationId">Relationship</FieldLabel>
                    <select
                        :id="relationId"
                        v-model="kind"
                        :disabled="busy"
                        class="h-9 w-full rounded-md border border-input-border bg-input-background px-3 py-1 text-sm text-text-primary shadow-sm focus-visible:border-transparent focus-visible:ring-2 focus-visible:ring-ring">
                        <option
                            v-for="(relationship, key) in taskRelationshipKinds"
                            :key="key"
                            :value="key">
                            {{ relationship.label }}
                        </option>
                    </select>
                </Field>

                <Field>
                    <FieldLabel :for="taskId">Related task</FieldLabel>
                    <Dropdown
                        v-model="pickerOpen"
                        :close-on-content-click="false"
                        @open="search = ''">
                        <template #trigger>
                            <button
                                :id="taskId"
                                type="button"
                                :disabled="busy || isPending || isError"
                                :aria-describedby="selectedTask ? statusId : undefined"
                                class="flex h-9 w-full items-center justify-between gap-2 rounded-md border border-input-border bg-input-background px-3 text-left text-sm text-text-primary shadow-sm focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50">
                                <span
                                    class="truncate"
                                    :class="{ 'text-text-secondary': !selectedTask }"
                                    >{{ selectedTask ?? 'Select a task' }}</span
                                >
                                <ChevronDownIcon class="size-4 shrink-0 text-icon-default" />
                            </button>
                        </template>
                        <template #content>
                            <ComboboxRoot
                                :open="true"
                                :ignore-filter="true"
                                :model-value="relatedTaskId"
                                class="w-[--reka-popper-anchor-width] max-w-[calc(100vw-2rem)]"
                                @update:model-value="selectTask"
                                @update:open="
                                    (value) => {
                                        if (!value) pickerOpen = false;
                                    }
                                ">
                                <ComboboxInput
                                    v-model="search"
                                    aria-label="Search tasks"
                                    placeholder="Search tasks…"
                                    class="w-full border-0 border-b border-popover-border bg-transparent px-3 py-2 text-sm placeholder:text-text-tertiary focus:ring-0" />
                                <ComboboxContent class="max-h-52 overflow-y-auto p-1">
                                    <ComboboxItem
                                        v-for="option in shownTasks"
                                        :key="option.id"
                                        :value="option.id"
                                        class="cursor-default rounded-sm px-2 py-1.5 text-sm text-text-primary outline-none data-[highlighted]:bg-accent data-[highlighted]:text-accent-foreground">
                                        <p class="break-words">{{ option.name }}</p>
                                        <p v-if="option.stage" class="text-xs text-text-secondary">
                                            {{ option.stage }}
                                        </p>
                                    </ComboboxItem>
                                    <p
                                        v-if="!shownTasks.length"
                                        class="px-2 py-2 text-xs text-text-secondary">
                                        No matching tasks
                                    </p>
                                </ComboboxContent>
                            </ComboboxRoot>
                        </template>
                    </Dropdown>
                </Field>

                <p
                    v-if="selectedTask"
                    :id="statusId"
                    aria-live="polite"
                    class="rounded-md bg-tertiary px-2.5 py-2 text-xs text-text-secondary">
                    This task
                    <strong class="text-text-primary">{{
                        taskRelationshipKinds[kind].label.toLowerCase()
                    }}</strong>
                    “{{ selectedTask }}”.
                </p>
                <p v-if="isError" role="alert" class="text-sm text-text-secondary">
                    Could not load task links.
                    <button type="button" class="underline" @click="refetch()">Retry</button>
                </p>

                <div
                    v-if="existingLinks.length"
                    class="border-t border-default-background-separator pt-3">
                    <p class="mb-1 text-xs font-medium text-text-secondary">Linked tasks</p>
                    <ul class="divide-y divide-row-separator">
                        <li
                            v-for="edge in existingLinks"
                            :key="edge.id"
                            class="flex items-center justify-between gap-2 py-1.5 text-xs">
                            <span class="min-w-0 break-words"
                                ><span class="text-text-secondary">{{
                                    taskRelationshipKinds[edge.kind].label
                                }}</span>
                                <span class="text-text-primary">{{
                                    taskNames.get(edge.related_task_id)
                                }}</span></span
                            >
                            <button
                                type="button"
                                :disabled="busy"
                                :aria-label="
                                    'Remove link to ' + taskNames.get(edge.related_task_id)
                                "
                                class="rounded-md p-1.5 text-text-secondary hover:bg-tertiary"
                                @click="remove(edge.id)">
                                <XMarkIcon class="size-4" />
                            </button>
                        </li>
                    </ul>
                </div>
            </FieldGroup>
        </template>
        <template #footer>
            <SecondaryButton type="button" :disabled="busy" @click="show = false"
                >Cancel</SecondaryButton
            >
            <PrimaryButton
                type="button"
                class="ms-3 disabled:opacity-50"
                :loading="busy"
                :disabled="busy || isPending || isError || !selectedTask"
                @click="submit"
                >Link task</PrimaryButton
            >
        </template>
    </DialogModal>
</template>
