<script setup lang="ts">
import { DropdownMenuCheckboxItem, DropdownMenuSeparator } from '@/packages/ui/src';
import type { Tag } from '@/packages/api/src';
import { NO_TAG_ID } from './constants';

defineProps<{ tags: Tag[] }>();
const model = defineModel<string[]>({ required: true });

function toggleTag(id: string) {
    model.value = model.value.includes(id)
        ? model.value.filter((tagId) => tagId !== id)
        : [...model.value, id];
}
</script>

<template>
    <DropdownMenuCheckboxItem
        :model-value="model.includes(NO_TAG_ID)"
        @select.prevent="toggleTag(NO_TAG_ID)">
        No tags
    </DropdownMenuCheckboxItem>
    <DropdownMenuSeparator v-if="tags.length" />
    <DropdownMenuCheckboxItem
        v-for="tag in tags"
        :key="tag.id"
        :model-value="model.includes(tag.id)"
        @select.prevent="toggleTag(tag.id)">
        <span class="truncate">{{ tag.name }}</span>
    </DropdownMenuCheckboxItem>
</template>
