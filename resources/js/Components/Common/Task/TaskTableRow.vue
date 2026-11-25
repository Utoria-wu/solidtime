<script setup lang="ts">
import type { TaskCategory } from '@/utils/useTaskHistory';
import type { Task } from '@/packages/api/src';
import { PlayIcon, StopIcon } from '@heroicons/vue/20/solid';
import { useTasksStore } from '@/utils/useTasks';
import TaskMoreOptionsDropdown from '@/Components/Common/Task/TaskMoreOptionsDropdown.vue';
import TableRow from '@/Components/TableRow.vue';
import { canDeleteTasks, canUpdateTasks } from '@/utils/permissions';
import TaskEditModal from '@/Components/Common/Task/TaskEditModal.vue';
import { computed, ref, inject, nextTick, type ComputedRef } from 'vue';
import { isAllowedToPerformPremiumAction } from '@/utils/billing';
import EstimatedTimeProgress from '@/packages/ui/src/EstimatedTimeProgress.vue';
import UpgradeBadge from '@/packages/ui/src/UpgradeBadge.vue';
import {
    formatDate,
    formatDateLocalized,
    formatDuration,
    formatHumanReadableDuration,
    getLocalizedDayJs,
} from '../../../packages/ui/src/utils/time';
import {
    elapsedTaskSeconds,
    type TaskTimer,
    type TaskTimerAction,
    type TaskTimerCompletion,
} from '@/utils/useTaskTimers';
import type { Organization } from '@/packages/api/src';
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import TextInput from '@/packages/ui/src/Input/TextInput.vue';
import TimeEntryNotesField from '@/packages/ui/src/TimeEntry/TimeEntryNotesField.vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import { Field, FieldGroup, FieldLabel } from '@/packages/ui/src/field';

const emit = defineEmits<{ openActivity: [] }>();

const props = defineProps<{
    task: Task;
    taskCategory?: TaskCategory;
    timer?: TaskTimer;
    timerReady: boolean;
    now: number;
    change: (
        taskId: string,
        action: TaskTimerAction,
        timer?: TaskTimer,
        completion?: TaskTimerCompletion
    ) => Promise<void>;
}>();

const saving = ref(false);
const directCompletionOpen = ref(false);
const completionTimerId = ref<string | null>(null);
const dialogAction = ref<'pause' | 'complete'>('complete');
const pauseVersion = ref<number | null>(null);
const eventName = ref('');
const notes = ref<string | null>(null);
const showPauseTimer = computed(
    () =>
        !props.task.is_canceled &&
        props.timerReady &&
        !!props.timer &&
        (props.timer.started_at !== null || props.timer.elapsed_seconds > 0)
);
const canPauseTimer = computed(
    () => showPauseTimer.value && !!props.timer && elapsedTaskSeconds(props.timer, props.now) > 0
);
const showTimerButton = computed(
    () =>
        !props.task.is_canceled &&
        props.timerReady &&
        (!!props.timer?.started_at || (!props.task.is_done && canUpdateTasks()))
);
const eventNameId = computed(() => `calendar-event-name-${props.task.id}`);
const timerAvailable = computed(
    () =>
        !!props.timer &&
        props.timer.id === completionTimerId.value &&
        (dialogAction.value === 'complete' ||
            (props.timer.started_at === null && props.timer.version === pauseVersion.value))
);
const canSaveCompletion = computed(
    () => !saving.value && (directCompletionOpen.value || timerAvailable.value)
);

function openCompletion() {
    if (!props.timer || saving.value) return;
    dialogAction.value = 'complete';
    eventName.value = props.task.name;
    notes.value = null;
    completionTimerId.value = props.timer.id;
}

function handleTimerButton() {
    const timer = props.timer;
    if (!timer) return void changeTimer('start');
    if (timer.started_at === null) return void changeTimer('resume');
    void changeTimer('stop');
}

async function openPause() {
    if (!canPauseTimer.value || !props.timer || saving.value) return;
    const sessionId = props.timer.id;
    if (props.timer.started_at !== null && !(await changeTimer('stop'))) return;
    await nextTick();
    const timer = props.timer;
    if (!timer || timer.id !== sessionId || timer.started_at !== null) return;

    dialogAction.value = 'pause';
    pauseVersion.value = timer.version;
    eventName.value = props.task.name;
    notes.value = null;
    completionTimerId.value = timer.id;
}

async function submitCompletion() {
    if (!canSaveCompletion.value) return;
    const note = notes.value?.trim() ? notes.value : null;
    if (directCompletionOpen.value) {
        saving.value = true;
        try {
            const result = await useTasksStore().updateTask(props.task.id, {
                name: props.task.name,
                is_done: true,
                completion_notes: note,
            });
            if (result) closeCompletion();
        } finally {
            saving.value = false;
        }
        return;
    }

    await changeTimer(dialogAction.value, {
        description: eventName.value.trim() || props.task.name,
        markdown_notes: note,
        ...(dialogAction.value === 'complete' ? { completion_notes: note } : {}),
    });
}

function closeCompletion() {
    completionTimerId.value = null;
    directCompletionOpen.value = false;
}

async function changeTimer(action: TaskTimerAction, completion?: TaskTimerCompletion) {
    if (saving.value) return false;
    saving.value = true;
    try {
        await props.change(props.task.id, action, props.timer, completion);
        if (action === 'complete' || action === 'pause') completionTimerId.value = null;
        return true;
    } catch {
        // The API notification shows the error.
        return false;
    } finally {
        saving.value = false;
    }
}

const organization = inject<ComputedRef<Organization>>('organization');

const today = computed(() => getLocalizedDayJs().format('YYYY-MM-DD'));
const overdue = computed(
    () =>
        !!props.task.due_date &&
        !props.task.is_done &&
        !props.task.is_canceled &&
        props.task.due_date < today.value
);
const dueToday = computed(
    () => props.task.due_date === today.value && !props.task.is_done && !props.task.is_canceled
);

function toggleCanceled() {
    void useTasksStore().updateTask(props.task.id, {
        name: props.task.name,
        is_canceled: !props.task.is_canceled,
    });
}

function deleteTask() {
    useTasksStore().deleteTask(props.task.id);
}

function markTaskAsDone() {
    if (!props.task.is_done) {
        if (props.timer) {
            openCompletion();
        } else {
            dialogAction.value = 'complete';
            eventName.value = props.task.name;
            notes.value = null;
            directCompletionOpen.value = true;
        }
        return;
    }
    useTasksStore().updateTask(props.task.id, {
        name: props.task.name,
        is_done: !props.task.is_done,
    });
}

const showTaskEditModal = ref(false);
</script>

<template>
    <TableRow>
        <div
            class="whitespace-nowrap min-w-0 flex py-4 pr-3 text-sm font-medium text-text-primary pl-4 sm:pl-6 lg:pl-8 3xl:pl-12"
            :class="$slots.timeline ? 'flex-col justify-center gap-1' : 'items-center space-x-5'">
            <button
                type="button"
                class="truncate text-left hover:underline"
                :title="task.name"
                @click="emit('openActivity')">
                {{ task.name }}
            </button>
            <span
                v-if="taskCategory"
                class="max-w-28 truncate rounded px-1.5 py-0.5 text-[11px] font-normal"
                :style="{ color: taskCategory.color, backgroundColor: taskCategory.color + '12' }"
                >{{ taskCategory.name }}</span
            >
            <slot name="planned-dates" />
        </div>
        <template v-if="!$slots.timeline">
            <div
                class="flex items-center whitespace-nowrap px-3 text-sm tabular-nums text-text-secondary">
                {{
                    task.created_at
                        ? formatDateLocalized(task.created_at, organization?.date_format)
                        : '—'
                }}
            </div>
            <div
                class="flex items-center whitespace-nowrap px-3 text-sm tabular-nums text-text-secondary">
                {{
                    task.done_at
                        ? formatDateLocalized(task.done_at, organization?.date_format)
                        : '—'
                }}
            </div>
        </template>
        <div
            v-if="!$slots.timeline"
            class="whitespace-nowrap px-3 py-4 flex items-center gap-1.5 text-sm"
            :class="
                overdue ? 'text-red-500' : dueToday ? 'text-accent-500' : 'text-text-secondary'
            ">
            <template v-if="task.due_date">
                <span>{{
                    dueToday ? 'Today' : formatDate(task.due_date, organization?.date_format)
                }}</span>
                <span v-if="overdue" class="text-xs">· Overdue</span>
            </template>
            <span v-else class="text-text-tertiary">—</span>
        </div>
        <div
            v-if="!$slots.timeline"
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
        <div
            v-if="!$slots.timeline"
            class="whitespace-nowrap px-3 flex items-center text-sm text-text-secondary">
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
        <div
            class="grid grid-cols-[2rem_2rem] items-center gap-1 whitespace-nowrap px-2 text-text-secondary">
            <button
                v-if="showTimerButton"
                type="button"
                class="inline-flex size-8 items-center justify-center rounded-md text-text-primary hover:bg-tertiary disabled:opacity-50"
                :disabled="saving"
                :title="
                    timer
                        ? timer.started_at === null
                            ? 'Resume timer'
                            : 'Stop timer'
                        : 'Start timer'
                "
                :aria-label="
                    timer
                        ? timer.started_at === null
                            ? 'Resume timer'
                            : 'Stop timer'
                        : 'Start timer'
                "
                @click="handleTimerButton">
                <StopIcon v-if="timer?.started_at" class="size-4" />
                <PlayIcon v-else class="size-4" />
            </button>
            <div v-if="canDeleteTasks() || canUpdateTasks() || showPauseTimer" class="col-start-2">
                <TaskMoreOptionsDropdown
                    :task="task"
                    :can-pause-timer="canPauseTimer"
                    :show-pause-timer="showPauseTimer"
                    :busy="saving"
                    @pause="openPause"
                    @done="markTaskAsDone"
                    @cancel="toggleCanceled"
                    @edit="showTaskEditModal = true"
                    @delete="deleteTask"></TaskMoreOptionsDropdown>
            </div>
        </div>
        <slot name="timeline" />
        <TaskEditModal v-model:show="showTaskEditModal" :task="task"></TaskEditModal>
        <DialogModal
            :show="directCompletionOpen || completionTimerId !== null"
            :closeable="!saving"
            @close="closeCompletion"
            @submit="submitCompletion">
            <template #title>{{
                dialogAction === 'pause' ? 'Pause task timer' : 'Complete task'
            }}</template>
            <template #content>
                <fieldset v-if="directCompletionOpen || timerAvailable" :disabled="saving">
                    <FieldGroup>
                        <Field>
                            <FieldLabel :for="eventNameId">{{
                                dialogAction === 'pause' ? 'Event name' : 'Task name'
                            }}</FieldLabel>
                            <TextInput
                                :id="eventNameId"
                                v-model="eventName"
                                type="text"
                                class="w-full"
                                autocomplete="off"
                                :maxlength="5000"
                                :readonly="dialogAction === 'complete'"
                                :disabled="saving" />
                        </Field>
                        <TimeEntryNotesField v-model="notes" :disabled="saving" />
                    </FieldGroup>
                </fieldset>
                <p v-else role="alert" class="mt-3">This timer is no longer available.</p>
            </template>
            <template #footer>
                <SecondaryButton :disabled="saving" @click="closeCompletion">
                    Cancel
                </SecondaryButton>
                <PrimaryButton
                    class="ms-3"
                    :disabled="!canSaveCompletion"
                    @click="submitCompletion">
                    {{
                        saving
                            ? 'Saving…'
                            : dialogAction === 'pause'
                              ? 'Save to Calendar'
                              : 'Complete task'
                    }}
                </PrimaryButton>
            </template>
        </DialogModal>
    </TableRow>
</template>

<style scoped></style>
