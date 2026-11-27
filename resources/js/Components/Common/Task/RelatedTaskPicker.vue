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
const projectNames = computed(
    () => new Map(projects.value.map((project) => [project.id, project.name]))
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
            const project = projectNames.value.get(task.project_id) ?? '';
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
                    <span v-if="selected" class="min-w-0 truncate">
                        {{ selected.name }}
                        <span
                            v-if="projectNames.get(selected.project_id)"
                            class="text-xs text-text-secondary">
                            · {{ projectNames.get(selected.project_id) }}
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
                            <p class="break-words">{{ task.name }}</p>
                            <p
                                v-if="projectNames.get(task.project_id)"
                                class="text-xs text-text-secondary">
                                {{ projectNames.get(task.project_id) }}
                            </p>
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
