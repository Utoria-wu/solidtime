<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import { ChevronDownIcon } from '@heroicons/vue/20/solid';
import { ComboboxContent, ComboboxInput, ComboboxItem, ComboboxRoot } from 'reka-ui';
import Dropdown from '@/packages/ui/src/Input/Dropdown.vue';
import { Field, FieldLabel } from '@/packages/ui/src/field';
import { useProjectsQuery } from '@/utils/useProjectsQuery';
import { useTasksQuery } from '@/utils/useTasksQuery';

const value = defineModel<string | null>({ required: true });
const props = defineProps<{ projectId: string; excludedIds?: string[]; disabled?: boolean }>();
const { tasks, isPending, isError, refetch } = useTasksQuery();
const { projects } = useProjectsQuery();
const projectsById = computed(
    () => new Map(projects.value.map((project) => [project.id, project]))
);
const open = ref(false);
const search = ref('');
const inputId = useId();
const selected = computed(() => tasks.value.find((task) => task.id === value.value));
const options = computed(() => {
    const query = search.value.trim().toLocaleLowerCase();
    const excluded = new Set(props.excludedIds);
    return tasks.value
        .filter((task) => {
            const project = projectsById.value.get(task.project_id)?.name ?? '';
            return (
                !excluded.has(task.id) &&
                `${task.name} ${project}`.toLocaleLowerCase().includes(query)
            );
        })
        .sort(
            (a, b) =>
                Number(a.project_id !== props.projectId) -
                    Number(b.project_id !== props.projectId) || a.name.localeCompare(b.name)
        );
});

function select(id: string | null) {
    if (!id) return;
    value.value = id;
    open.value = false;
}
</script>

<template>
    <Field>
        <FieldLabel :for="inputId">Related task</FieldLabel>
        <Dropdown v-model="open" :close-on-content-click="false" @open="search = ''">
            <template #trigger>
                <button
                    :id="inputId"
                    type="button"
                    :disabled="disabled || isPending || isError"
                    class="flex h-9 w-full items-center justify-between gap-2 rounded-md border border-input-border bg-input-background px-3 text-left text-sm text-text-primary shadow-sm focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50">
                    <span
                        v-if="selected"
                        class="flex min-w-0 flex-1 items-center justify-between gap-2">
                        <span class="min-w-0 truncate">{{ selected.name }}</span>
                        <span
                            v-if="projectsById.has(selected.project_id)"
                            class="flex max-w-[45%] shrink-0 items-center gap-1.5 text-xs text-text-secondary">
                            <span
                                class="size-1.5 shrink-0 rounded-full"
                                aria-hidden="true"
                                :style="{
                                    backgroundColor: projectsById.get(selected.project_id)?.color,
                                }" />
                            <span class="truncate">{{
                                projectsById.get(selected.project_id)?.name
                            }}</span>
                        </span>
                    </span>
                    <span v-else class="truncate text-text-secondary">Select a task</span>
                    <ChevronDownIcon class="size-4 shrink-0 text-icon-default" />
                </button>
            </template>
            <template #content>
                <ComboboxRoot
                    :open="true"
                    :ignore-filter="true"
                    :model-value="value"
                    class="w-[--reka-popper-anchor-width] max-w-[calc(100vw-2rem)]"
                    @update:model-value="select"
                    @update:open="open = $event">
                    <ComboboxInput
                        v-model="search"
                        aria-label="Search tasks"
                        placeholder="Search tasks or projects…"
                        class="w-full border-0 border-b border-popover-border bg-transparent px-3 py-2 text-sm placeholder:text-text-tertiary focus:ring-0" />
                    <ComboboxContent class="max-h-52 overflow-y-auto p-1">
                        <ComboboxItem
                            v-for="task in options"
                            :key="task.id"
                            :value="task.id"
                            class="cursor-default rounded-sm px-2 py-1.5 text-sm text-text-primary outline-none data-[highlighted]:bg-accent data-[highlighted]:text-accent-foreground">
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="min-w-0 break-words">{{ task.name }}</span>
                                <span
                                    v-if="projectsById.has(task.project_id)"
                                    class="max-w-[45%] shrink-0 truncate text-xs opacity-75">
                                    {{ projectsById.get(task.project_id)?.name }}
                                </span>
                            </div>
                        </ComboboxItem>
                        <p v-if="!options.length" class="px-2 py-2 text-xs text-text-secondary">
                            No matching tasks
                        </p>
                    </ComboboxContent>
                </ComboboxRoot>
            </template>
        </Dropdown>
        <p v-if="isError" role="alert" class="text-sm text-text-secondary">
            Could not load tasks.
            <button type="button" class="underline" @click="refetch()">Retry</button>
        </p>
    </Field>
</template>
