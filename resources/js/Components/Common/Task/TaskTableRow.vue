<script setup lang="ts">
import type { Task } from '@/packages/api/src';
import { CheckIcon, PauseIcon, PlayIcon } from '@heroicons/vue/20/solid';
import { useTasksStore } from '@/utils/useTasks';
import TaskMoreOptionsDropdown from '@/Components/Common/Task/TaskMoreOptionsDropdown.vue';
import TableRow from '@/Components/TableRow.vue';
import { canDeleteTasks, canUpdateTasks } from '@/utils/permissions';
import TaskEditModal from '@/Components/Common/Task/TaskEditModal.vue';
import { ref, inject, type ComputedRef } from 'vue';
import { isAllowedToPerformPremiumAction } from '@/utils/billing';
import EstimatedTimeProgress from '@/packages/ui/src/EstimatedTimeProgress.vue';
import UpgradeBadge from '@/packages/ui/src/UpgradeBadge.vue';
import { formatDuration, formatHumanReadableDuration } from '../../../packages/ui/src/utils/time';
import { elapsedTaskSeconds, type TaskTimer, type TaskTimerAction } from '@/utils/useTaskTimers';
import type { Organization } from '@/packages/api/src';

const props = defineProps<{
    task: Task;
    timer?: TaskTimer;
    timerReady: boolean;
    now: number;
    change: (taskId: string, action: TaskTimerAction, timer?: TaskTimer) => Promise<void>;
}>();

const saving = ref(false);

async function changeTimer(action: TaskTimerAction) {
    if (saving.value) return;
    saving.value = true;
    try {
        await props.change(props.task.id, action, props.timer);
    } catch {
        // The API notification shows the error.
    } finally {
        saving.value = false;
    }
}

const organization = inject<ComputedRef<Organization>>('organization');

function deleteTask() {
    useTasksStore().deleteTask(props.task.id);
}

function markTaskAsDone() {
    if (!props.task.is_done && props.timer) {
        void changeTimer('complete');
        return;
    }
    useTasksStore().updateTask(props.task.id, {
        ...props.task,
        is_done: !props.task.is_done,
    });
}

const showTaskEditModal = ref(false);
</script>

<template>
    <TableRow>
        <div
            class="whitespace-nowrap min-w-0 flex items-center space-x-5 3xl:pl-12 py-4 pr-3 text-sm font-medium text-text-primary pl-4 sm:pl-6 lg:pl-8 3xl:pl-12">
            <span class="truncate" :title="task.name">
                {{ task.name }}
            </span>
        </div>
        <div
            class="whitespace-nowrap px-3 py-4 text-sm text-text-secondary flex space-x-1 items-center font-medium">
            <span v-if="task.spent_time">
                {{
                    formatHumanReadableDuration(
                        task.spent_time,
                        organization?.interval_format,
                        organization?.number_format
                    )
                }}
            </span>
            <span v-else> -- </span>
        </div>
        <div class="whitespace-nowrap px-3 flex items-center text-sm text-text-secondary">
            <UpgradeBadge
                v-if="!isAllowedToPerformPremiumAction()"
                feature="estimates"></UpgradeBadge>
            <EstimatedTimeProgress
                v-else-if="task.estimated_time"
                :estimated="task.estimated_time"
                :current="task.spent_time"></EstimatedTimeProgress>
            <span v-else> -- </span>
        </div>
        <div
            class="whitespace-nowrap px-3 py-4 text-sm text-text-secondary flex space-x-1 items-center font-medium">
            <template v-if="timer">
                <span
                    class="size-2 rounded-full"
                    :class="timer.started_at === null ? 'bg-gray-400' : 'bg-teal-500'"
                    :title="timer.started_at === null ? 'Paused' : 'Running'"></span>
                <span class="tabular-nums">{{
                    formatDuration(elapsedTaskSeconds(timer, now))
                }}</span>
            </template>
            <span v-else>--</span>
        </div>
        <div class="relative whitespace-nowrap flex items-center gap-1 px-2 text-text-secondary">
            <button
                v-if="timerReady && (timer?.started_at || (!task.is_done && canUpdateTasks()))"
                type="button"
                class="inline-flex size-8 items-center justify-center rounded hover:text-text-primary disabled:opacity-50"
                :disabled="saving"
                :title="
                    timer
                        ? timer.started_at === null
                            ? 'Resume timer'
                            : 'Pause timer'
                        : 'Start timer'
                "
                :aria-label="
                    timer
                        ? timer.started_at === null
                            ? 'Resume timer'
                            : 'Pause timer'
                        : 'Start timer'
                "
                @click="
                    changeTimer(timer ? (timer.started_at === null ? 'resume' : 'pause') : 'start')
                ">
                <PauseIcon v-if="timer?.started_at" class="size-4" />
                <PlayIcon v-else class="size-4" />
            </button>
            <button
                v-if="timer && canUpdateTasks()"
                type="button"
                class="inline-flex size-8 items-center justify-center rounded hover:text-text-primary disabled:opacity-50"
                :disabled="saving"
                title="Complete task and save to Calendar"
                aria-label="Complete task and save to Calendar"
                @click="changeTimer('complete')">
                <CheckIcon class="size-4" />
            </button>
            <TaskMoreOptionsDropdown
                v-if="canDeleteTasks()"
                :task="task"
                @done="markTaskAsDone"
                @edit="showTaskEditModal = true"
                @delete="deleteTask"></TaskMoreOptionsDropdown>
        </div>
        <TaskEditModal v-model:show="showTaskEditModal" :task="task"></TaskEditModal>
    </TableRow>
</template>

<style scoped></style>
