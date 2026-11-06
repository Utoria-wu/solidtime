<script setup lang="ts">
import { computed } from 'vue';
import type { Task } from '@/packages/api/src';

const props = defineProps<{
    tasks: Pick<Task, 'is_done'>[];
    isLoading?: boolean;
    isError?: boolean;
}>();

const total = computed(() => props.tasks.length);
const completed = computed(() => props.tasks.filter((task) => task.is_done).length);
const percentage = computed(() => (total.value === 0 ? 0 : (completed.value / total.value) * 100));
const description = computed(
    () => `${completed.value} completed, ${total.value - completed.value} remaining`
);
</script>

<template>
    <span v-if="isLoading" class="text-text-secondary text-xs">Loading tasks…</span>
    <span v-else-if="isError" class="text-text-secondary text-xs">Tasks unavailable</span>
    <span v-else-if="total === 0" class="text-text-tertiary text-xs">No tasks</span>
    <div v-else class="w-full" :title="description">
        <div
            role="progressbar"
            aria-label="Task completion"
            :aria-valuemin="0"
            :aria-valuemax="total"
            :aria-valuenow="completed"
            :aria-valuetext="description"
            class="bg-tertiary h-1 rounded relative overflow-hidden w-full">
            <div
                class="h-full transition-all"
                :class="completed === total ? 'bg-green-500' : 'bg-accent-200'"
                :style="{ width: percentage + '%' }"></div>
        </div>
        <div
            class="text-xs font-semibold pt-1.5 flex items-center justify-between gap-2 tabular-nums">
            <span>{{ completed }} / {{ total }} Done</span>
            <span>{{ Math.round(percentage) }}%</span>
        </div>
    </div>
</template>
