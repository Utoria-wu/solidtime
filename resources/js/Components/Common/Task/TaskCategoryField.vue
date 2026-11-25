<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import { canCreateTags, canUpdateTags } from '@/utils/permissions';
import TaskCategoryManager from './TaskCategoryManager.vue';
import { useTaskCategories, type TaskCategory } from '@/utils/useTaskHistory';
import { Field, FieldLabel } from '@/packages/ui/src/field';
const model = defineModel<string | null>({ default: null });
const { categories, isError } = useTaskCategories();
const inputId = useId();
const manage = ref(false);
const initialCategoryId = ref<string>();
const selected = computed(() => categories.value.find((item) => item.id === model.value));

function openManager(id?: string) {
    initialCategoryId.value = id;
    manage.value = true;
}

function selectSavedCategory(category: TaskCategory) {
    if (!category.is_archived) model.value = category.id;
}
const options = computed(() =>
    categories.value.filter((item) => !item.is_archived || item.id === model.value)
);
</script>
<template>
    <Field>
        <div class="flex items-center justify-between gap-2">
            <FieldLabel :for="inputId">Category</FieldLabel>
            <div class="flex gap-3 text-xs">
                <button
                    v-if="canCreateTags()"
                    type="button"
                    class="text-text-secondary underline underline-offset-2"
                    @click="openManager()">
                    New category
                </button>
                <button
                    v-if="canUpdateTags() && selected"
                    type="button"
                    class="text-text-secondary underline underline-offset-2"
                    @click="openManager(selected.id)">
                    Edit category
                </button>
            </div>
        </div>
        <select
            :id="inputId"
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
    <TaskCategoryManager
        v-model:show="manage"
        :category-id="initialCategoryId"
        select-on-save
        @saved="selectSavedCategory" />
</template>
