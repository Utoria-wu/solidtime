<script setup lang="ts">
import type { Task } from '@/packages/api/src';
import { CheckIcon, PauseIcon, PlayIcon } from '@heroicons/vue/20/solid';
import { useTasksStore } from '@/utils/useTasks';
import TaskMoreOptionsDropdown from '@/Components/Common/Task/TaskMoreOptionsDropdown.vue';
import TableRow from '@/Components/TableRow.vue';
import { canDeleteTasks, canUpdateTasks } from '@/utils/permissions';
import TaskEditModal from '@/Components/Common/Task/TaskEditModal.vue';
import { computed, ref, inject, type ComputedRef } from 'vue';
import { isAllowedToPerformPremiumAction } from '@/utils/billing';
import EstimatedTimeProgress from '@/packages/ui/src/EstimatedTimeProgress.vue';
import UpgradeBadge from '@/packages/ui/src/UpgradeBadge.vue';
import {
    formatDate,
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
import { Field, FieldLabel } from '@/packages/ui/src/field';

const props = defineProps<{
    task: Task;
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
const completionTimerId = ref<string | null>(null);
const eventName = ref('');
const notes = ref<string | null>(null);
const eventNameId = computed(() => `calendar-event-name-${props.task.id}`);
const canSaveCompletion = computed(
    () =>
        !saving.value &&
        props.timer?.id === completionTimerId.value &&
        eventName.value.trim().length > 0
);

function openCompletion() {
    if (!props.timer || saving.value) return;
    eventName.value = props.task.name;
    notes.value = null;
    completionTimerId.value = props.timer.id;
}

function submitCompletion() {
    if (canSaveCompletion.value) {
        void changeTimer('complete', {
            description: eventName.value.trim(),
            markdown_notes: notes.value?.trim() ? notes.value : null,
        });
    }
}

async function changeTimer(action: TaskTimerAction, completion?: TaskTimerCompletion) {
    if (saving.value) return;
    saving.value = true;
    try {
        await props.change(props.task.id, action, props.timer, completion);
        if (action === 'complete') completionTimerId.value = null;
    } catch {
        // The API notification shows the error.
    } finally {
        saving.value = false;
    }
}

const organization = inject<ComputedRef<Organization>>('organization');

const today = computed(() => getLocalizedDayJs().format('YYYY-MM-DD'));
const overdue = computed(
    () => !!props.task.due_date && !props.task.is_done && props.task.due_date < today.value
);
const dueToday = computed(() => props.task.due_date === today.value && !props.task.is_done);

function deleteTask() {
    useTasksStore().deleteTask(props.task.id);
}

function markTaskAsDone() {
    if (!props.task.is_done && props.timer) {
        openCompletion();
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
            class="whitespace-nowrap min-w-0 flex items-center space-x-5 3xl:pl-12 py-4 pr-3 text-sm font-medium text-text-primary pl-4 sm:pl-6 lg:pl-8 3xl:pl-12">
            <span class="truncate" :title="task.name">
                {{ task.name }}
            </span>
        </div>
        <div
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
                @click="openCompletion">
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
        <DialogModal
            :show="completionTimerId !== null"
            :closeable="!saving"
            max-width="lg"
            @close="completionTimerId = null"
            @submit="submitCompletion">
            <template #title>Save to Calendar</template>
            <template #content>
                <Field>
                    <FieldLabel :for="eventNameId">Event name</FieldLabel>
                    <TextInput
                        :id="eventNameId"
                        v-model="eventName"
                        type="text"
                        class="w-full"
                        autocomplete="off"
                        :maxlength="5000"
                        :disabled="saving"
                        autofocus
                        @keydown.enter.exact.prevent="!$event.isComposing && submitCompletion()" />
                </Field>
                <template v-if="timer?.id === completionTimerId">
                    <fieldset :disabled="saving" class="mt-4">
                        <TimeEntryNotesField v-model="notes" />
                    </fieldset>
                    <p class="mt-3">
                        Each timed segment will use this event name and notes. Saving also marks the
                        task as Done.
                    </p>
                </template>
                <p v-else role="alert" class="mt-3">This timer is no longer available.</p>
            </template>
            <template #footer>
                <SecondaryButton :disabled="saving" @click="completionTimerId = null">
                    Cancel
                </SecondaryButton>
                <PrimaryButton
                    class="ms-3"
                    :disabled="!canSaveCompletion"
                    @click="submitCompletion">
                    {{ saving ? 'Saving…' : 'Save and complete' }}
                </PrimaryButton>
            </template>
        </DialogModal>
    </TableRow>
</template>

<style scoped></style>
