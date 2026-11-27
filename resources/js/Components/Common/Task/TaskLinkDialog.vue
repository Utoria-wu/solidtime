<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import { XMarkIcon } from '@heroicons/vue/20/solid';
import type { Task } from '@/packages/api/src';
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import { Field, FieldGroup, FieldLabel } from '@/packages/ui/src/field';
import { useTasksQuery } from '@/utils/useTasksQuery';
import { useProjectsQuery } from '@/utils/useProjectsQuery';
import RelatedTaskPicker from './RelatedTaskPicker.vue';
import {
    taskRelationshipKinds,
    useTaskRelationships,
    type TaskRelationshipKind,
} from '@/utils/useTaskRelationships';

const props = defineProps<{ task: Task }>();
const show = defineModel<boolean>('show', { required: true });
const { tasks } = useTasksQuery();
const { projects } = useProjectsQuery();
const { relationships, isPending, isError, refetch, link, unlink } = useTaskRelationships(
    props.task.project_id
);
const relationId = useId();
const kind = ref<TaskRelationshipKind>('fixes');
const relatedTaskId = ref<string | null>(null);
const busy = ref(false);

const taskById = computed(() => new Map(tasks.value.map((task) => [task.id, task])));
const projectById = computed(() => new Map(projects.value.map((project) => [project.id, project])));
const existingLinks = computed(() =>
    relationships.value.filter((edge) => edge.task_id === props.task.id)
);
const excludedTaskIds = computed(() => [
    props.task.id,
    ...existingLinks.value.map((edge) => edge.related_task_id),
]);
const selectedTask = computed(() =>
    relatedTaskId.value ? taskById.value.get(relatedTaskId.value) : undefined
);

function targetLabel(id: string) {
    const task = taskById.value.get(id);
    if (!task) return 'Related task';
    const project = projectById.value.get(task.project_id);
    return project && task.project_id !== props.task.project_id
        ? `${task.name} · ${project.name}`
        : task.name;
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

                <RelatedTaskPicker
                    v-model="relatedTaskId"
                    :project-id="task.project_id"
                    :excluded-ids="excludedTaskIds"
                    :disabled="busy || isPending || isError" />

                <p
                    v-if="selectedTask"
                    aria-live="polite"
                    class="rounded-md bg-tertiary px-2.5 py-2 text-xs text-text-secondary">
                    This task
                    <strong class="text-text-primary">{{
                        taskRelationshipKinds[kind].label.toLowerCase()
                    }}</strong>
                    “{{ targetLabel(selectedTask.id) }}”.
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
                                    targetLabel(edge.related_task_id)
                                }}</span></span
                            >
                            <button
                                type="button"
                                :disabled="busy"
                                :aria-label="'Remove link to ' + targetLabel(edge.related_task_id)"
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
