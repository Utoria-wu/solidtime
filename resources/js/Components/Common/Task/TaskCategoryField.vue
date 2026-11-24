<script setup lang="ts">
import { computed } from 'vue';
import { useTaskCategories } from '@/utils/useTaskHistory';
import { Field, FieldLabel } from '@/packages/ui/src/field';
const model = defineModel<string | null>({ default: null });
const { categories, isError } = useTaskCategories();
const options = computed(() =>
    categories.value.filter((item) => !item.is_archived || item.id === model.value)
);
</script>
<template>
    <Field>
        <FieldLabel for="task-category">Category</FieldLabel>
        <select
            id="task-category"
            v-model="model"
            class="block w-full rounded-lg border border-default-background-separator bg-default-background px-3 py-2.5 text-sm shadow-sm">
            <option :value="null">Uncategorized</option>
            <option v-for="item in options" :key="item.id" :value="item.id">
                {{ item.name }}{{ item.is_archived ? ' (archived)' : '' }}
            </option>
        </select>
        <span v-if="isError" class="text-xs text-text-secondary"
            >Categories could not be loaded.</span
        >
    </Field>
</template>
