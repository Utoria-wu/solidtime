<script setup lang="ts">
import { computed, useId } from 'vue';
import { useTasksQuery } from '@/utils/useTasksQuery';
import TextInput from '@/packages/ui/src/Input/TextInput.vue';
import { Field, FieldLabel } from '@/packages/ui/src/field';

const props = defineProps<{ projectId: string }>();
const stage = defineModel<string>({ required: true });
const inputId = useId();
const listId = useId();
const { tasks } = useTasksQuery();
const suggestions = computed(() => {
    const stages = new Set<string>();
    for (const task of tasks.value) {
        const value = task.stage?.trim();
        if (task.project_id === props.projectId && value) stages.add(value);
    }
    return [...stages].sort((a, b) => a.localeCompare(b));
});
</script>

<template>
    <Field>
        <FieldLabel :for="inputId">Stage</FieldLabel>
        <TextInput
            :id="inputId"
            v-model="stage"
            :list="listId"
            class="w-full"
            placeholder="Choose or enter a stage"
            maxlength="100"
            autocomplete="off" />
        <datalist :id="listId">
            <option v-for="suggestion in suggestions" :key="suggestion" :value="suggestion" />
        </datalist>
    </Field>
</template>
