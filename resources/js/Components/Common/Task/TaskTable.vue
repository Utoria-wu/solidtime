<script setup lang="ts">
import { useInterfaceLanguage } from '@/utils/useInterfaceLanguage';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import { PlusCircleIcon } from '@heroicons/vue/24/solid';
import { ChevronLeftIcon, ChevronRightIcon, PlusIcon } from '@heroicons/vue/16/solid';
import { computed, ref, watch } from 'vue';
import { TooltipProvider } from '@/packages/ui/src';
import { getDayJsInstance, getLocalizedDayJs } from '@/packages/ui/src/utils/time';
import { useTaskCategories } from '@/utils/useTaskHistory';
import ProjectTaskHistory from './ProjectTaskHistory.vue';
import TaskScheduleTrack from './TaskScheduleTrack.vue';
import { createReusableTemplate, useTimestamp } from '@vueuse/core';
import { useTaskTimers } from '@/utils/useTaskTimers';
import TaskActivityDialog from './TaskActivityDialog.vue';
import TaskLinkDialog from './TaskLinkDialog.vue';
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
    allowHistory?: boolean;
    historyTasks?: Task[];
    focusedTaskId?: string | null;
    initialView?: 'list' | 'schedule' | 'history';
    scheduleProjects?: { id: string; name: string }[];
}>();

const emit = defineEmits<{
    viewChange: [view: 'list' | 'schedule' | 'history'];
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

const { categories: taskCategories } = useTaskCategories();
const categoryMap = computed(() => new Map(taskCategories.value.map((item) => [item.id, item])));
const createTask = ref(false);
const locatedTaskId = ref<string | null>(null);
const activityTaskId = ref<string | null>(null);
const activityOpen = ref(false);
const activityTask = computed(() =>
    (props.historyTasks ?? props.tasks).find((task) => task.id === activityTaskId.value)
);
const linkingTask = ref<Task | null>(null);
const linkOpen = ref(false);

function openLink(task: Task) {
    linkingTask.value = task;
    linkOpen.value = true;
}
function openActivity(task: Task) {
    activityTaskId.value = task.id;
    activityOpen.value = true;
}
const { timers, change, ready } = useTaskTimers();
const timersByTask = computed(() => new Map(timers.value.map((timer) => [timer.task_id, timer])));
const timestamp = useTimestamp({ interval: 1000 });
const now = computed(() => Math.floor(timestamp.value / 1000));
const view = ref<'list' | 'schedule' | 'history'>(props.initialView ?? 'list');
const [DefineViewSwitcher, ViewSwitcher] = createReusableTemplate();
const showHistory = computed(
    () => props.allowHistory && view.value === 'history' && !!props.projectId
);
const viewOptions = computed(
    () =>
        [
            'list',
            ...(props.allowSchedule ? ['schedule'] : []),
            ...(props.allowHistory ? ['history'] : []),
        ] as ('list' | 'schedule' | 'history')[]
);
function changeView(option: 'list' | 'schedule' | 'history') {
    view.value = option;
    emit('viewChange', option);
}
watch(
    () => props.allowSchedule,
    (enabled) => {
        if (!enabled && !props.scheduleProjects && view.value === 'schedule') changeView('list');
    }
);
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
    { id: 'ready', label: 'Scheduled' },
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
function plannedDates(task: Task) {
    const { start_date: start, due_date: due } = task;
    const format = (date: string) => {
        const value = getDayJsInstance()(date);
        return value.format(value.year() === days.value[0]!.year() ? 'MMM D' : 'MMM D, YYYY');
    };
    if (start && due) return start === due ? format(start) : format(start) + ' – ' + format(due);
    if (due) return t('Due date') + ' · ' + format(due);
    if (start) {
        return task.is_done || task.is_canceled
            ? format(start)
            : format(start) + ' · ' + t('Ongoing');
    }
    return t('No dates set');
}
</script>

<template>
    <TaskActivityDialog v-if="activityTask" v-model:open="activityOpen" :task="activityTask" />
    <TaskLinkDialog
        v-if="linkOpen && linkingTask"
        :key="linkingTask.id"
        v-model:show="linkOpen"
        :task="linkingTask" />
    <TaskCreateModal v-if="projectId" v-model:show="createTask" :project-id="projectId" />
    <DefineViewSwitcher>
        <div
            v-if="allowSchedule || allowHistory"
            role="group"
            aria-label="Task view"
            class="inline-flex shrink-0 gap-1 rounded-lg bg-tertiary p-1">
            <button
                v-for="option in viewOptions"
                :key="option"
                type="button"
                :aria-pressed="view === option"
                class="rounded-md px-3 py-1.5 text-sm capitalize text-text-secondary aria-pressed:bg-default-background aria-pressed:text-text-primary aria-pressed:shadow-sm"
                @click="changeView(option)">
                {{ option === 'history' ? 'History' : t(option === 'list' ? 'List' : 'Schedule') }}
            </button>
        </div>
    </DefineViewSwitcher>
    <div
        v-if="!showHistory && (allowSchedule || scheduleProjects || allowHistory)"
        class="flex flex-wrap items-center justify-between gap-3 py-3">
        <ViewSwitcher />
        <div class="ml-auto flex flex-wrap items-center justify-end gap-x-4 gap-y-2">
            <slot name="toolbar" />
            <div v-if="showSchedule" class="flex flex-wrap items-center gap-2 text-sm">
                <button
                    :aria-disabled="weekOffset === 0"
                    type="button"
                    class="rounded-md px-2.5 py-1.5 text-text-secondary hover:bg-tertiary hover:text-text-primary aria-disabled:opacity-50"
                    @click="weekOffset = 0">
                    {{ t('Today') }}
                </button>
                <div
                    class="inline-flex items-center gap-1 rounded-lg border border-default-background-separator p-0.5"
                    role="group"
                    aria-label="Schedule date range">
                    <button
                        type="button"
                        class="schedule-nav"
                        aria-label="Previous week"
                        @click="weekOffset--">
                        <ChevronLeftIcon class="size-4" />
                    </button>
                    <span class="px-1.5 text-center font-medium tabular-nums" aria-live="polite">{{
                        weekLabel
                    }}</span>
                    <button
                        type="button"
                        class="schedule-nav"
                        aria-label="Next week"
                        @click="weekOffset++">
                        <ChevronRightIcon class="size-4" />
                    </button>
                </div>
            </div>
            <slot name="actions" />
        </div>
    </div>
    <ProjectTaskHistory
        v-if="showHistory && projectId"
        :project-id="projectId"
        :tasks="historyTasks ?? tasks"
        @open-activity="openActivity">
        <template #navigation><ViewSwitcher /></template>
        <template #actions><slot name="actions" /></template>
    </ProjectTaskHistory>
    <TooltipProvider v-else :delay-duration="180">
        <div class="flow-root overflow-x-auto">
            <div class="inline-block min-w-full align-middle">
                <div
                    data-testid="task_table"
                    role="table"
                    class="grid min-w-full"
                    :class="{ 'schedule-table': showSchedule }"
                    :style="{
                        gridTemplateColumns: showSchedule
                            ? 'minmax(16rem, 22rem) 5.5rem 5.25rem minmax(34rem, 1fr)'
                            : 'minmax(10rem, 1fr) repeat(2, minmax(7.5rem, max-content)) 11rem minmax(80px, auto) minmax(95px, auto) 8rem 5.75rem',
                        '--schedule-days': scheduleDays,
                    }">
                    <TaskTableHeading
                        v-if="!showSchedule"
                        :sort-column="sortColumn"
                        :sort-direction="sortDirection"
                        :desc-first-columns="descFirstColumns"
                        @sort="handleSort"></TaskTableHeading>
                    <div v-else role="row" class="contents text-xs text-text-secondary">
                        <div role="columnheader" class="flex items-center px-4 font-medium">
                            {{ t('Tasks') }}
                        </div>
                        <div role="columnheader" class="col-span-2">
                            <span class="sr-only">Timer and actions</span>
                        </div>
                        <div
                            role="columnheader"
                            aria-label="Two-week timeline"
                            class="schedule-days">
                            <div
                                v-for="(day, index) in days"
                                :key="weekDates[index]"
                                class="schedule-day flex flex-col items-center justify-center gap-0.5 py-2"
                                :class="{ 'schedule-today': weekDates[index] === today }"
                                :aria-current="weekDates[index] === today ? 'date' : undefined"
                                :title="day.format('dddd, MMM D, YYYY')">
                                {{ day.format('ddd') }}
                                <strong
                                    class="schedule-date flex size-6 items-center justify-center rounded-md text-sm text-text-primary"
                                    >{{ day.format('D') }}</strong
                                >
                            </div>
                        </div>
                    </div>
                    <div v-if="sortedTasks.length === 0" class="col-span-full py-16 text-center">
                        <PlusCircleIcon class="w-8 text-icon-default inline pb-2"></PlusCircleIcon>
                        <h3 class="text-text-primary font-semibold">
                            {{
                                t(showSchedule && projectId ? 'No active tasks' : 'No tasks found')
                            }}
                        </h3>
                        <p v-if="projectId && canCreateTasks() && !$slots.actions" class="pb-5">
                            Create your first task now!
                        </p>
                        <SecondaryButton
                            v-if="projectId && canCreateTasks() && !$slots.actions"
                            :icon="PlusIcon"
                            @click="createTask = true"
                            >Create your First Task
                        </SecondaryButton>
                    </div>
                    <template v-for="group in rowGroups" :key="group.id">
                        <div v-if="showSchedule" role="row" class="contents">
                            <div
                                role="cell"
                                class="schedule-group-label col-span-3 flex items-center gap-2 px-4 py-2 text-xs font-medium text-text-secondary">
                                {{ scheduleProjects ? group.label : t(group.label) }}
                                <span class="tabular-nums text-text-tertiary">{{
                                    group.tasks.length
                                }}</span>
                            </div>
                            <TaskScheduleTrack
                                class="schedule-group-track"
                                :dates="weekDates"
                                :today="today"
                                aria-hidden="true" />
                        </div>
                        <TaskTableRow
                            v-for="task in group.tasks"
                            :key="task.id"
                            :task="task"
                            :locate="
                                view === 'list' &&
                                !showSchedule &&
                                task.id === focusedTaskId &&
                                task.id !== locatedTaskId
                            "
                            @located="locatedTaskId = task.id"
                            :class="{
                                'schedule-row': showSchedule,
                                'project-list-row': projectId && !showSchedule,
                            }"
                            :task-category="categoryMap.get(task.category_id ?? '')"
                            :timer="timersByTask.get(task.id)"
                            :timer-ready="ready"
                            :now="now"
                            :change="change"
                            @open-activity="openActivity(task)"
                            @link-task="openLink(task)">
                            <template v-if="showSchedule" #planned-dates>
                                <span class="truncate text-xs font-normal text-text-secondary">
                                    {{ plannedDates(task) }}
                                    <span v-if="scheduleProjects && task.is_done">
                                        · {{ t('Done') }}</span
                                    >
                                    <span
                                        v-else-if="
                                            scheduleProjects && taskGroup(task) === 'overdue'
                                        "
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
                                    :tone="task.is_done ? 'done' : taskGroup(task)"
                                    @open="openActivity(task)" />
                            </template>
                        </TaskTableRow>
                    </template>
                </div>
            </div>
        </div>
    </TooltipProvider>
</template>

<style scoped>
.schedule-nav {
    display: grid;
    place-items: center;
    width: 1.875rem;
    height: 1.875rem;
    border-radius: 0.375rem;
    color: var(--theme-color-text-secondary);
}
.schedule-nav:hover {
    background: color-mix(in oklch, currentColor 9%, transparent);
}
.schedule-table {
    --schedule-line: color-mix(
        in oklch,
        var(--theme-color-default-background-separator) 46%,
        transparent
    );
    --schedule-week-line: color-mix(
        in oklch,
        var(--theme-color-default-background-separator) 82%,
        transparent
    );
    --schedule-today: color-mix(in oklch, #70b3cf 38%, transparent);
    border-top: 1px solid var(--theme-color-default-background-separator);
}
.schedule-table :deep(.schedule-row > *),
:deep(.project-list-row > *) {
    background-color: transparent;
}
.schedule-table :deep(.schedule-row:hover > *),
.schedule-table :deep(.schedule-row:focus-within > *) {
    background-color: color-mix(in oklch, var(--theme-color-text-secondary) 4%, transparent);
}
.schedule-group-label,
.schedule-group-track {
    border-top: 1px solid var(--schedule-week-line);
}
.schedule-days {
    display: grid;
    grid-template-columns: repeat(var(--schedule-days), minmax(0, 1fr));
}
.schedule-day {
    border-inline-start: 1px solid var(--schedule-line);
}
.schedule-day:nth-child(7n + 1) {
    border-inline-start-color: var(--schedule-week-line);
}
.schedule-day:nth-child(7n),
.schedule-day:nth-child(7n - 1) {
    color: var(--theme-color-text-tertiary);
}
.schedule-today .schedule-date {
    color: var(--theme-color-text-primary);
    border: 1px solid var(--schedule-today);
    background: color-mix(in oklch, var(--schedule-today) 24%, transparent);
}
</style>
