<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useTimestamp } from '@vueuse/core';
import { computed } from 'vue';
import { formatDuration } from '@/packages/ui/src/utils/time';
import { useTasksQuery } from '@/utils/useTasksQuery';
import { elapsedTaskSeconds, useTaskTimers } from '@/utils/useTaskTimers';

const { timers } = useTaskTimers();
const { tasks } = useTasksQuery();
const timestamp = useTimestamp({ interval: 1000 });
const runningTasks = computed(() => {
    const taskById = new Map(tasks.value.map((task) => [task.id, task]));
    return timers.value.flatMap((timer) => {
        const task = taskById.get(timer.task_id);
        return timer.started_at !== null && task ? [{ timer, task }] : [];
    });
});
</script>

<template>
    <section
        v-if="runningTasks.length"
        aria-labelledby="running-tasks-heading"
        class="mt-2 border-t border-default-background-separator px-2 py-2">
        <h2 id="running-tasks-heading" class="mb-1 px-1 text-xs font-medium text-text-secondary">
            Running tasks
        </h2>
        <ul class="max-h-[25dvh] overflow-y-auto">
            <li v-for="{ timer, task } in runningTasks" :key="timer.id">
                <Link
                    :href="route('projects.show', { project: task.project_id })"
                    class="flex items-center gap-2 rounded-md px-1 py-1.5 text-xs text-text-primary hover:bg-tertiary"
                    :title="task.name">
                    <span aria-hidden="true" class="size-1.5 shrink-0 rounded-full bg-teal-500" />
                    <span class="min-w-0 flex-1 truncate">{{ task.name }}</span>
                    <span class="shrink-0 tabular-nums text-text-secondary">
                        {{
                            formatDuration(elapsedTaskSeconds(timer, Math.floor(timestamp / 1000)))
                        }}
                    </span>
                </Link>
            </li>
        </ul>
    </section>
</template>
