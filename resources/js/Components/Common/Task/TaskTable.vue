<script setup lang="ts">
import { useInterfaceLanguage } from '@/utils/useInterfaceLanguage';
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

export type SortColumn = 'name' | 'created_at' | 'done_at' | 'due_date' | 'spent_time' | 'progress';
export type { SortDirection } from '@/utils/useSortableTable';

const props = defineProps<{
    projectId?: string;
    tasks: Task[];
    sortColumn: SortColumn;
    sortDirection: SortDirection;
    allowSchedule?: boolean;
    initialView?: 'list' | 'schedule';
    scheduleProjects?: { id: string; name: string }[];
}>();

const emit = defineEmits<{
    sort: [column: SortColumn, direction: SortDirection];
}>();

const columns: SortableColumnDef<Task, SortColumn>[] = [
    { id: 'created_at', sortDescFirst: true, accessorFn: (task) => task.created_at ?? undefined },
    { id: 'done_at', sortDescFirst: true, accessorFn: (task) => task.done_at ?? undefined },
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
const view = ref<'list' | 'schedule'>(props.initialView ?? 'list');
const showSchedule = computed(
    () => !!props.scheduleProjects || (props.allowSchedule && view.value === 'schedule')
);
const scheduleDays = 14;
const weekOffset = ref(0);
const today = computed(() =>
    getLocalizedDayJs(new Date(timestamp.value).toISOString()).format('YYYY-MM-DD')
);
const days = computed(() => {
    const date = getDayJsInstance()(today.value);
    const monday = date.subtract((date.day() + 6) % 7, 'day').add(weekOffset.value, 'week');
    return Array.from({ length: scheduleDays }, (_, index) => monday.add(index, 'day'));
});
const weekDates = computed(() => days.value.map((day) => day.format('YYYY-MM-DD')));
const weekLabel = computed(() => {
    const first = days.value[0]!;
    const last = days.value[scheduleDays - 1]!;
    return `${first.format(first.year() === last.year() ? 'MMM D' : 'MMM D, YYYY')} – ${last.format('MMM D, YYYY')}`;
});
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
    if (props.scheduleProjects) {
        const byProject = new Map<string, Task[]>();
        for (const task of sortedTasks.value) {
            const projectTasks = byProject.get(task.project_id) ?? [];
            projectTasks.push(task);
            byProject.set(task.project_id, projectTasks);
        }
        return props.scheduleProjects
            .map((project) => ({
                id: project.id,
                label: project.name,
                tasks: byProject.get(project.id) ?? [],
            }))
            .filter((group) => group.tasks.length);
    }
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
const { t } = useInterfaceLanguage();
</script>

<template>
    <TaskActivityDialog v-if="activityTask" v-model:open="activityOpen" :task="activityTask" />
    <TaskCreateModal v-if="projectId" v-model:show="createTask" :project-id="projectId" />
    <div
        v-if="allowSchedule || scheduleProjects"
        class="flex flex-wrap items-center justify-between gap-3 py-3">
        <div
            v-if="allowSchedule"
            role="group"
            aria-label="Task view"
            class="flex gap-1 rounded-lg bg-tertiary p-1">
            <button
                v-for="option in ['list', 'schedule'] as const"
                :key="option"
                type="button"
                :aria-pressed="view === option"
                class="rounded-md px-3 py-1.5 text-sm capitalize text-text-secondary aria-pressed:bg-default-background aria-pressed:text-text-primary aria-pressed:shadow-sm"
                @click="view = option">
                {{ t(option === 'list' ? 'List' : 'Schedule') }}
            </button>
        </div>
        <div v-if="showSchedule" class="ml-auto flex items-center gap-2 text-sm">
            <SecondaryButton @click="weekOffset = 0">{{ t('Today') }}</SecondaryButton>
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
                        ? 'minmax(13rem, 1fr) 5rem 7rem minmax(34rem, 2.5fr)'
                        : 'minmax(10rem, 1fr) repeat(2, minmax(7.5rem, max-content)) 11rem minmax(80px, auto) minmax(95px, auto) 8rem 8rem',
                    '--schedule-days': scheduleDays,
                }">
                <TaskTableHeading
                    v-if="!showSchedule"
                    :sort-column="sortColumn"
                    :sort-direction="sortDirection"
                    :desc-first-columns="descFirstColumns"
                    @sort="handleSort"></TaskTableHeading>
                <div v-else role="row" class="contents text-xs text-text-secondary">
                    <div role="columnheader" class="px-4 py-3">{{ t('Task / Planned dates') }}</div>
                    <div role="columnheader" class="px-3 py-3">{{ t('Timer') }}</div>
                    <div role="columnheader"><span class="sr-only">Actions</span></div>
                    <div role="columnheader" aria-label="Two-week timeline" class="schedule-days">
                        <div
                            v-for="(day, index) in days"
                            :key="weekDates[index]"
                            class="schedule-day flex flex-col items-center justify-center gap-0.5 py-2"
                            :class="{ 'schedule-today': weekDates[index] === today }"
                            :aria-current="weekDates[index] === today ? 'date' : undefined"
                            :title="day.format('dddd, MMM D, YYYY')">
                            {{ day.format('dd') }}
                            <strong
                                class="schedule-date flex size-6 items-center justify-center rounded-md text-sm text-text-primary"
                                >{{ day.format('D') }}</strong
                            >
                        </div>
                    </div>
                </div>
                <div v-if="sortedTasks.length === 0" class="col-span-full py-24 text-center">
                    <PlusCircleIcon class="w-8 text-icon-default inline pb-2"></PlusCircleIcon>
                    <h3 class="text-text-primary font-semibold">{{ t('No tasks found') }}</h3>
                    <p v-if="projectId && canCreateTasks()" class="pb-5">
                        Create your first task now!
                    </p>
                    <SecondaryButton
                        v-if="projectId && canCreateTasks()"
                        :icon="PlusIcon"
                        @click="createTask = true"
                        >Create your First Task
                    </SecondaryButton>
                </div>
                <template v-for="group in rowGroups" :key="group.id">
                    <div v-if="showSchedule" role="row" class="contents">
                        <div
                            role="cell"
                            class="col-span-3 bg-tertiary px-4 py-2 text-xs font-semibold">
                            {{ scheduleProjects ? group.label : t(group.label) }}
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
                                <template v-if="!task.start_date && !task.due_date">{{
                                    t('No dates set')
                                }}</template>
                                <span v-if="scheduleProjects && task.is_done">
                                    · {{ t('Done') }}</span
                                >
                                <span
                                    v-else-if="taskGroup(task) === 'overdue'"
                                    class="text-red-500">
                                    · Overdue</span
                                >
                            </span>
                        </template>
                        <template v-if="showSchedule" #timeline>
                            <TaskScheduleTrack
                                :dates="weekDates"
                                :today="today"
                                :task="task"
                                :tone="task.is_done ? 'done' : taskGroup(task)" />
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
    grid-template-columns: repeat(var(--schedule-days), minmax(0, 1fr));
}
.schedule-day:nth-child(7n + 1) {
    border-inline-start: 1px solid var(--theme-color-default-background-separator);
}
.schedule-today .schedule-date {
    background: color-mix(in oklch, #70b3cf 20%, var(--theme-color-default-background));
}
</style>
