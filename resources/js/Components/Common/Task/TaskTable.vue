<script setup lang="ts">
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import { PlusCircleIcon } from '@heroicons/vue/24/solid';
import { PlusIcon } from '@heroicons/vue/16/solid';
import { computed, ref } from 'vue';
import { getDayJsInstance, getLocalizedDayJs } from '@/packages/ui/src/utils/time';
import TaskScheduleTrack from './TaskScheduleTrack.vue';
import { useTimestamp } from '@vueuse/core';
import { useTaskTimers } from '@/utils/useTaskTimers';
import TaskActivityDialog from './TaskActivityDialog.vue';
import TaskTableRow from '@/Components/Common/Task/TaskTableRow.vue';
import TaskTableHeading from '@/Components/Common/Task/TaskTableHeading.vue';
import TaskCreateModal from '@/Components/Common/Task/TaskCreateModal.vue';
import { canCreateTasks } from '@/utils/permissions';
import type { Task } from '@/packages/api/src';
import {
    useSortableTable,
    type SortableColumnDef,
    type SortDirection,
} from '@/utils/useSortableTable';

export type SortColumn = 'name' | 'due_date' | 'spent_time' | 'progress';
export type { SortDirection } from '@/utils/useSortableTable';

const props = defineProps<{
    projectId: string;
    tasks: Task[];
    sortColumn: SortColumn;
    sortDirection: SortDirection;
    allowSchedule?: boolean;
}>();

const emit = defineEmits<{
    sort: [column: SortColumn, direction: SortDirection];
}>();

const columns: SortableColumnDef<Task, SortColumn>[] = [
    {
        id: 'name',
        accessorFn: (row: Task) => row.name.toLowerCase(),
    },
    {
        id: 'due_date',
        accessorFn: (row: Task) => row.due_date ?? undefined,
    },
    {
        id: 'spent_time',
        sortDescFirst: true,
        accessorFn: (row: Task) => row.spent_time,
    },
    {
        id: 'progress',
        sortDescFirst: true,
        accessorFn: (row: Task) => {
            if (!row.estimated_time) return undefined;
            return (row.spent_time / row.estimated_time) * 100;
        },
    },
];

const {
    sortedRows: sortedTasks,
    descFirstColumns,
    nextDirection,
} = useSortableTable({
    data: () => props.tasks,
    columns: () => columns,
    sortColumn: () => props.sortColumn,
    sortDirection: () => props.sortDirection,
    tieBreakColumn: 'name',
});

function handleSort(column: SortColumn) {
    emit('sort', column, nextDirection(column));
}

const createTask = ref(false);
const activityTaskId = ref<string | null>(null);
const activityOpen = ref(false);
const activityTask = computed(() => props.tasks.find((task) => task.id === activityTaskId.value));
function openActivity(task: Task) {
    activityTaskId.value = task.id;
    activityOpen.value = true;
}
const { timers, change, ready } = useTaskTimers();
const timersByTask = computed(() => new Map(timers.value.map((timer) => [timer.task_id, timer])));
const timestamp = useTimestamp({ interval: 1000 });
const now = computed(() => Math.floor(timestamp.value / 1000));
const view = ref<'list' | 'schedule'>('list');
const showSchedule = computed(() => props.allowSchedule && view.value === 'schedule');
const weekOffset = ref(0);
const today = computed(() =>
    getLocalizedDayJs(new Date(timestamp.value).toISOString()).format('YYYY-MM-DD')
);
const days = computed(() => {
    const date = getDayJsInstance()(today.value);
    const monday = date.subtract((date.day() + 6) % 7, 'day').add(weekOffset.value, 'week');
    return Array.from({ length: 7 }, (_, index) => monday.add(index, 'day'));
});
const weekDates = computed(() => days.value.map((day) => day.format('YYYY-MM-DD')));
const weekLabel = computed(
    () => `${days.value[0]!.format('MMM D')} – ${days.value[6]!.format('MMM D, YYYY')}`
);
const groups = [
    { id: 'overdue', label: 'Overdue' },
    { id: 'ready', label: 'Ready to work' },
    { id: 'upcoming', label: 'Upcoming' },
    { id: 'unscheduled', label: 'Unscheduled' },
] as const;
function taskGroup(task: Task) {
    if (task.due_date && task.due_date < today.value) return 'overdue';
    if (task.start_date && task.start_date > today.value) return 'upcoming';
    if (task.start_date || task.due_date) return 'ready';
    return 'unscheduled';
}
const rowGroups = computed(() => {
    if (!showSchedule.value) return [{ id: 'list', label: '', tasks: sortedTasks.value }];
    return groups
        .map((group) => ({
            ...group,
            tasks: props.tasks
                .filter((task) => taskGroup(task) === group.id)
                .sort((left, right) => {
                    const date = (task: Task) =>
                        (group.id === 'upcoming' ? task.start_date : task.due_date) ?? '';
                    return (
                        Number(!date(left)) - Number(!date(right)) ||
                        date(left).localeCompare(date(right)) ||
                        left.name.localeCompare(right.name)
                    );
                }),
        }))
        .filter((group) => group.tasks.length);
});
</script>

<template>
    <TaskActivityDialog v-if="activityTask" v-model:open="activityOpen" :task="activityTask" />
    <TaskCreateModal v-model:show="createTask" :project-id="props.projectId"></TaskCreateModal>
    <div v-if="allowSchedule" class="flex flex-wrap items-center justify-between gap-3 py-3">
        <div role="group" aria-label="Task view" class="flex gap-1 rounded-lg bg-tertiary p-1">
            <button
                v-for="option in ['list', 'schedule'] as const"
                :key="option"
                type="button"
                :aria-pressed="view === option"
                class="rounded-md px-3 py-1.5 text-sm capitalize text-text-secondary aria-pressed:bg-default-background aria-pressed:text-text-primary aria-pressed:shadow-sm"
                @click="view = option">
                {{ option }}
            </button>
        </div>
        <div v-if="showSchedule" class="flex items-center gap-2 text-sm">
            <SecondaryButton @click="weekOffset = 0">Today</SecondaryButton>
            <SecondaryButton aria-label="Previous week" @click="weekOffset--">‹</SecondaryButton>
            <span class="min-w-40 text-center font-medium" aria-live="polite">{{ weekLabel }}</span>
            <SecondaryButton aria-label="Next week" @click="weekOffset++">›</SecondaryButton>
        </div>
    </div>
    <div class="flow-root overflow-x-auto">
        <div class="inline-block min-w-full align-middle">
            <div
                data-testid="task_table"
                role="table"
                class="grid min-w-full"
                :class="{ 'schedule-table': showSchedule }"
                :style="{
                    gridTemplateColumns: showSchedule
                        ? 'minmax(12rem, 1fr) 6rem 6rem 7rem minmax(28rem, 2fr)'
                        : 'minmax(10rem, 1fr) 11rem minmax(80px, auto) minmax(95px, auto) 8rem 8rem',
                }">
                <TaskTableHeading
                    v-if="!showSchedule"
                    :sort-column="sortColumn"
                    :sort-direction="sortDirection"
                    :desc-first-columns="descFirstColumns"
                    @sort="handleSort"></TaskTableHeading>
                <div v-else role="row" class="contents text-xs text-text-secondary">
                    <div role="columnheader" class="px-4 py-3">Task / Planned dates</div>
                    <div role="columnheader" class="px-3 py-3">Tracked</div>
                    <div role="columnheader" class="px-3 py-3">Timer</div>
                    <div role="columnheader"><span class="sr-only">Actions</span></div>
                    <div role="columnheader" aria-label="Weekly timeline" class="schedule-days">
                        <div
                            v-for="(day, index) in days"
                            :key="weekDates[index]"
                            class="schedule-day flex flex-col items-center justify-center gap-0.5 py-2"
                            :class="{ 'schedule-today': weekDates[index] === today }"
                            :aria-current="weekDates[index] === today ? 'date' : undefined">
                            {{ day.format('ddd') }}
                            <strong class="text-sm text-text-primary">{{ day.format('D') }}</strong>
                            <span v-if="weekDates[index] === today">Today</span>
                        </div>
                    </div>
                </div>
                <div v-if="sortedTasks.length === 0" class="col-span-full py-24 text-center">
                    <PlusCircleIcon class="w-8 text-icon-default inline pb-2"></PlusCircleIcon>
                    <h3 class="text-text-primary font-semibold">No tasks found</h3>
                    <p v-if="canCreateTasks()" class="pb-5">Create your first task now!</p>
                    <SecondaryButton
                        v-if="canCreateTasks()"
                        :icon="PlusIcon"
                        @click="createTask = true"
                        >Create your First Task
                    </SecondaryButton>
                </div>
                <template v-for="group in rowGroups" :key="group.id">
                    <div v-if="showSchedule" role="row" class="contents">
                        <div
                            role="cell"
                            class="col-span-4 bg-tertiary px-4 py-2 text-xs font-semibold">
                            {{ group.label }}
                            <span class="ml-1 text-text-secondary">{{ group.tasks.length }}</span>
                        </div>
                        <TaskScheduleTrack :dates="weekDates" :today="today" aria-hidden="true" />
                    </div>
                    <TaskTableRow
                        v-for="task in group.tasks"
                        :key="task.id"
                        :task="task"
                        :timer="timersByTask.get(task.id)"
                        :timer-ready="ready"
                        :now="now"
                        :change="change"
                        @open-activity="openActivity(task)">
                        <template v-if="showSchedule" #planned-dates>
                            <span class="text-xs font-normal text-text-secondary">
                                <template v-if="task.start_date">{{
                                    getDayJsInstance()(task.start_date).format('MMM D, YYYY')
                                }}</template>
                                <template v-if="task.due_date">
                                    {{ task.start_date ? ' → ' : 'Due '
                                    }}{{ getDayJsInstance()(task.due_date).format('MMM D, YYYY') }}
                                </template>
                                <template v-if="!task.start_date && !task.due_date"
                                    >No dates set</template
                                >
                            </span>
                        </template>
                        <template v-if="showSchedule" #timeline>
                            <TaskScheduleTrack
                                :dates="weekDates"
                                :today="today"
                                :task="task"
                                :tone="group.id" />
                        </template>
                    </TaskTableRow>
                </template>
            </div>
        </div>
    </div>
</template>

<style scoped>
.schedule-table {
    overflow: hidden;
    border: 1px solid var(--theme-color-default-background-separator);
    border-radius: 0.75rem;
}
.schedule-days {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
}
.schedule-day {
    background: color-mix(
        in oklch,
        var(--color-text-secondary) 3%,
        var(--theme-color-default-background)
    );
}
.schedule-day:nth-child(n + 6) {
    background: color-mix(
        in oklch,
        var(--color-text-secondary) 7%,
        var(--theme-color-default-background)
    );
}
.schedule-day.schedule-today {
    background: color-mix(in oklch, #70b3cf 17%, var(--theme-color-default-background));
}
</style>
