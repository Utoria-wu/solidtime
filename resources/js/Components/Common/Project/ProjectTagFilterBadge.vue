<script setup lang="ts">
import { computed } from 'vue';
import { TagIcon } from '@heroicons/vue/16/solid';
import type { Tag } from '@/packages/api/src';
import BaseFilterBadge from './BaseFilterBadge.vue';
import ProjectTagFilterItems from './ProjectTagFilterItems.vue';
import { NO_TAG_ID } from './constants';

const props = defineProps<{ tags: Tag[] }>();
const model = defineModel<string[]>({ required: true });
const emit = defineEmits<{ remove: [] }>();

const label = computed(() => {
    if (model.value.length !== 1) return model.value.length + ' selected';
    if (model.value[0] === NO_TAG_ID) return 'No tags';
    return props.tags.find((tag) => tag.id === model.value[0])?.name ?? '1 selected';
});
</script>

<template>
    <BaseFilterBadge :icon="TagIcon" :label="label" filter-name="Tags" @remove="emit('remove')">
        <ProjectTagFilterItems v-model="model" :tags="tags" />
    </BaseFilterBadge>
</template>
