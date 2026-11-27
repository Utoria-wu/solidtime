<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import {
    AdjustmentsHorizontalIcon,
    ArrowLeftIcon,
    ChevronRightIcon,
    MagnifyingGlassIcon,
} from '@heroicons/vue/20/solid';
import { api, type Task, type TimeEntry } from '@/packages/api/src';
import { getLocalizedDayJs } from '@/packages/ui/src/utils/time';
import { Popover, PopoverContent, PopoverTrigger } from '@/packages/ui/src/popover';
import { historyKinds, useTaskCategories, useTaskHistory } from '@/utils/useTaskHistory';
import { useTaskRelationships } from '@/utils/useTaskRelationships';
import {
    canCreateTags,
    canUpdateTags,
    canUpdateTasks,
    canViewAllTimeEntries,
} from '@/utils/permissions';
import { getCurrentMembershipId, getCurrentOrganizationId } from '@/utils/useUser';
import ProjectHistoryTimeline, { type ProjectHistoryRecord } from './ProjectHistoryTimeline.vue';
import TaskRelationshipMap from './TaskRelationshipMap.vue';
import TaskCategoryManager from './TaskCategoryManager.vue';
import TaskEditModal from './TaskEditModal.vue';
import TaskMoreOptionsDropdown from './TaskMoreOptionsDropdown.vue';

const props = defineProps<{ projectId: string; tasks: Task[] }>();
const emit = defineEmits<{ openActivity: [task: Task] }>();
const { categories, isError: categoryError } = useTaskCategories();
const legacy = useTaskHistory(props.projectId);
const taskLinks = useTaskRelationships(props.projectId);

const views = [
    { key: 'timeline', label: 'Timeline' },
    { key: 'tasks', label: 'Tasks' },
    { key: 'problems', label: 'Problems' },
] as const;
type HistoryView = (typeof views)[number]['key'];
const view = ref<HistoryView>('timeline');
const selectedTaskId = ref<string | null>(null);
const selectedProblemId = ref<string | null>(null);
const search = ref('');
const stage = ref<string | null>(null);
const category = ref<string | null>(null);
const activityType = ref('all');
const oldestFirst = ref(false);
const filtersOpen = ref(false);
const canManageCategories = computed(
    () => view.value === 'tasks' && (canCreateTags() || canUpdateTags())
);
const filterCount = computed(
    () =>
        Number(stage.value !== null) +
        Number(category.value !== null) +
        Number(view.value === 'timeline' && activityType.value !== 'all')
);
const manage = ref(false);
const editTaskId = ref<string | null>(null);
const detailHeading = ref<HTMLElement | null>(null);
const categoryMap = computed(() => new Map(categories.value.map((item) => [item.id, item])));
const taskMap = computed(() => new Map(props.tasks.map((task) => [task.id, task])));
const editingTask = computed(() => taskMap.value.get(editTaskId.value ?? ''));
const activeTask = computed(() => taskMap.value.get(selectedTaskId.value ?? ''));
const stages = computed(() =>
    [
        ...new Set(
            props.tasks.map((task) => task.stage).filter((value): value is string => Boolean(value))
        ),
    ].sort()
);

const calendar = useQuery({
    queryKey: computed(
        () =>
            [
                'timeEntries',
                'project-history',
                getCurrentOrganizationId(),
                props.projectId,
                canViewAllTimeEntries() ? null : getCurrentMembershipId(),
            ] as const
    ),
    enabled: () => Boolean(getCurrentOrganizationId() && getCurrentMembershipId()),
    queryFn: async ({ queryKey: [, , organization, project, member], signal }) => {
        if (!organization) throw new Error('No organization');
        const entries: TimeEntry[] = [];
        for (;;) {
            const page = await api.getTimeEntries({
                params: { organization },
                queries: {
                    project_ids: [project],
                    member_id: member ?? undefined,
                    active: 'false',
                    offset: entries.length || undefined,
                },
                signal,
            });
            entries.push(...page.data);
            if (!page.data.length || entries.length >= page.meta.total) return entries;
        }
    },
});

function calendarUrl(id: string, date: string) {
    return (
        '/calendar?' +
        new URLSearchParams({ date: getLocalizedDayJs(date).format('YYYY-MM-DD'), entry: id })
    );
}

const records = computed(() => {
    const result: ProjectHistoryRecord[] = [];
    for (const task of props.tasks) {
        if (task.created_at)
            result.push({
                id: `created-${task.id}`,
                task,
                type: 'created',
                title: task.name,
                date: task.created_at,
                label: 'Created',
                note: task.markdown_notes ?? null,
                color: '#5389b0',
            });
        if (task.done_at)
            result.push({
                id: `completed-${task.id}`,
                task,
                type: 'completed',
                title: task.name,
                date: task.done_at,
                label: 'Completed',
                note: task.completion_notes ?? null,
                color: '#4e936d',
            });
    }
    for (const event of calendar.data.value ?? []) {
        if (!event.end) continue;
        const task = (event.task_id && taskMap.value.get(event.task_id)) || null;
        result.push({
            id: event.id,
            task,
            type: 'calendar',
            title: event.description?.trim() || task?.name || 'Untitled Calendar entry',
            date: event.start,
            label: 'Calendar',
            note: event.markdown_notes ?? null,
            color: '#789487',
            calendarUrl: calendarUrl(event.id, event.start),
        });
    }
    for (const entry of legacy.entries.value) {
        const task = taskMap.value.get(entry.task_id);
        if (!task) continue;
        const kind = historyKinds[entry.kind];
        result.push({
            id: entry.id,
            task,
            type: 'record',
            title: entry.title.trim() || task.name,
            date: entry.occurred_at,
            label: kind.label,
            note: entry.markdown_notes,
            color: kind.color,
            legacy: entry,
            calendarUrl: entry.source
                ? calendarUrl(entry.source.id, entry.source.start)
                : undefined,
        });
    }
    return result.sort(
        (a, b) => Date.parse(b.date) - Date.parse(a.date) || a.id.localeCompare(b.id)
    );
});

const recordsByTask = computed(() => {
    const result = new Map<string, ProjectHistoryRecord[]>();
    for (const record of records.value) {
        if (!record.task) continue;
        const taskRecords = result.get(record.task.id) ?? [];
        taskRecords.push(record);
        result.set(record.task.id, taskRecords);
    }
    return result;
});
const taskRecords = computed(() =>
    [...(recordsByTask.value.get(activeTask.value?.id ?? '') ?? [])].reverse()
);
const relationshipTaskIds = computed(
    () =>
        new Set(
            taskLinks.relationships.value.flatMap((edge) => [edge.task_id, edge.related_task_id])
        )
);

function matchesFilters(task: Task | null) {
    return (
        (stage.value === null || (task !== null && (task.stage ?? '') === stage.value)) &&
        (category.value === null || (task !== null && (task.category_id ?? '') === category.value))
    );
}

function matchesSearch(...values: (string | null | undefined)[]) {
    const query = search.value.trim().toLocaleLowerCase();
    return !query || values.some((value) => value?.toLocaleLowerCase().includes(query));
}

const timeline = computed(() => {
    const filtered = records.value.filter((record) => {
        if (
            !matchesFilters(record.task) ||
            !matchesSearch(record.title, record.task?.name, record.note, record.label)
        )
            return false;
        if (activityType.value === 'unlinked') return record.type === 'calendar' && !record.task;
        if (activityType.value === 'calendar') return record.type === 'calendar';
        return true;
    });
    return oldestFirst.value ? filtered.reverse() : filtered;
});
const taskList = computed(() =>
    props.tasks.filter(
        (task) =>
            matchesFilters(task) &&
            matchesSearch(task.name, task.markdown_notes, task.stage, task.completion_notes)
    )
);

const problems = computed(() => {
    const rows = records.value
        .filter((record) => record.legacy?.kind === 'problem')
        .flatMap((root) => {
            if (!root.task) return [];
            const updates = (recordsByTask.value.get(root.task.id) ?? [])
                .filter((record) => record.legacy?.issue_id === root.id)
                .reverse();
            const outcome =
                [...updates].reverse().find((record) => record.legacy?.outcome)?.legacy?.outcome ??
                root.legacy?.outcome;
            return [
                {
                    id: root.id,
                    task: root.task,
                    title: root.title,
                    date: root.date,
                    note: root.note,
                    outcome,
                    updates: [root, ...updates],
                },
            ];
        });
    const explicit = new Set(rows.map((problem) => problem.task.id));
    for (const edge of taskLinks.relationships.value) {
        if (edge.kind !== 'fixes' || explicit.has(edge.related_task_id)) continue;
        const task = taskMap.value.get(edge.related_task_id);
        if (!task) continue;
        explicit.add(task.id);
        rows.push({
            id: `problem-${task.id}`,
            task,
            title: task.name,
            date: task.created_at,
            note: task.markdown_notes ?? null,
            outcome: null,
            updates: [...(recordsByTask.value.get(task.id) ?? [])].reverse(),
        });
    }
    return rows.sort((a, b) => Date.parse(b.date) - Date.parse(a.date));
});
const visibleProblems = computed(() =>
    problems.value.filter(
        (row) =>
            matchesFilters(row.task) &&
            matchesSearch(
                row.title,
                row.task.name,
                row.note,
                ...row.updates.map((update) => update.note)
            )
    )
);
const activeProblem = computed(() =>
    problems.value.find((row) => row.id === selectedProblemId.value)
);
const backLabel = computed(
    () => 'Back to ' + (views.find((item) => item.key === view.value)?.label ?? 'History')
);
const loading = computed(() => calendar.isPending.value || legacy.isPending.value);
const historyError = computed(
    () => calendar.isError.value || legacy.isError.value || taskLinks.isError.value
);
const detailTask = computed(() => activeTask.value ?? activeProblem.value?.task);

function selectView(next: HistoryView) {
    view.value = next;
    filtersOpen.value = false;
    selectedTaskId.value = null;
    selectedProblemId.value = null;
}

function clearFilters() {
    stage.value = null;
    category.value = null;
    if (view.value === 'timeline') activityType.value = 'all';
}

function selectTask(task: Task) {
    selectedTaskId.value = task.id;
    selectedProblemId.value = null;
}

function status(task: Task) {
    return task.is_canceled ? 'Canceled' : task.is_done ? 'Done' : 'Active';
}

function retry() {
    if (calendar.isError.value) void calendar.refetch();
    if (legacy.isError.value) void legacy.refetch();
    if (taskLinks.isError.value) void taskLinks.refetch();
}

watch([selectedTaskId, selectedProblemId], async ([taskId, problemId]) => {
    if (!taskId && !problemId) return;
    await nextTick();
    detailHeading.value?.focus();
});
watch(editingTask, (task) => {
    if (!task) editTaskId.value = null;
});
</script>

<template>
    <div data-testid="project_history" class="pb-6">
        <div
            data-testid="history-toolbar"
            class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 border-b border-row-separator py-3">
            <div class="flex max-w-full flex-wrap items-center gap-x-3 gap-y-2">
                <slot name="navigation" />
                <div
                    role="group"
                    aria-label="History view"
                    class="hidden items-center gap-0.5 border-l border-row-separator pl-2 sm:inline-flex">
                    <button
                        v-for="item in views"
                        :key="item.key"
                        :aria-pressed="item.key === view"
                        type="button"
                        class="rounded-md px-2.5 py-1.5 text-sm text-text-secondary hover:bg-tertiary aria-pressed:bg-tertiary aria-pressed:font-medium aria-pressed:text-text-primary"
                        @click="selectView(item.key)">
                        {{ item.label }}
                    </button>
                </div>
                <select
                    :value="view"
                    aria-label="History view"
                    class="h-8 rounded-md border-0 bg-tertiary py-1 pl-2 pr-6 text-sm sm:hidden"
                    @change="selectView(($event.target as HTMLSelectElement).value as HistoryView)">
                    <option v-for="item in views" :key="item.key" :value="item.key">
                        {{ item.label }}
                    </option>
                </select>
            </div>
            <div class="ml-auto flex max-w-full flex-wrap items-center justify-end gap-2">
                <template v-if="!detailTask">
                    <label class="relative block">
                        <MagnifyingGlassIcon
                            class="pointer-events-none absolute left-2.5 top-2 size-4 text-icon-default"
                            aria-hidden="true" />
                        <input
                            v-model="search"
                            type="search"
                            aria-label="Search history"
                            placeholder="Search…"
                            class="h-8 w-28 rounded-md border border-default-background-separator bg-default-background py-1 pl-8 pr-2 text-sm xl:w-36" />
                    </label>
                    <Popover
                        v-if="
                            stages.length ||
                            categories.length ||
                            view === 'timeline' ||
                            canManageCategories
                        "
                        v-model:open="filtersOpen">
                        <PopoverTrigger as-child>
                            <button
                                type="button"
                                :aria-label="
                                    filterCount ? `Filters, ${filterCount} active` : 'Filters'
                                "
                                title="Filters"
                                class="inline-flex h-8 items-center gap-1.5 rounded-md px-2 text-sm text-text-secondary hover:bg-tertiary data-[state=open]:bg-tertiary">
                                <AdjustmentsHorizontalIcon class="size-4" aria-hidden="true" />
                                <span class="hidden xl:inline">Filters</span>
                                <span v-if="filterCount" class="tabular-nums">{{
                                    filterCount
                                }}</span>
                            </button>
                        </PopoverTrigger>
                        <PopoverContent
                            align="end"
                            aria-label="History filters"
                            class="grid w-60 gap-3 p-3 text-sm">
                            <label v-if="stages.length" class="grid gap-1">
                                Stage
                                <select v-model="stage" class="history-control">
                                    <option :value="null">All stages</option>
                                    <option value="">No stage</option>
                                    <option v-for="item in stages" :key="item" :value="item">
                                        {{ item }}
                                    </option>
                                </select>
                            </label>
                            <label v-if="categories.length" class="grid gap-1">
                                Category
                                <select v-model="category" class="history-control">
                                    <option :value="null">All categories</option>
                                    <option value="">Uncategorized</option>
                                    <option
                                        v-for="item in categories"
                                        :key="item.id"
                                        :value="item.id">
                                        {{ item.name }}
                                    </option>
                                </select>
                            </label>
                            <template v-if="view === 'timeline'">
                                <label class="grid gap-1">
                                    Activity type
                                    <select
                                        v-model="activityType"
                                        aria-label="Activity type"
                                        class="history-control">
                                        <option value="all">All activity</option>
                                        <option value="calendar">Calendar</option>
                                        <option value="unlinked">Calendar without task</option>
                                    </select>
                                </label>
                                <label class="grid gap-1">
                                    Order
                                    <select v-model="oldestFirst" class="history-control">
                                        <option :value="false">Newest first</option>
                                        <option :value="true">Oldest first</option>
                                    </select>
                                </label>
                            </template>
                            <button
                                v-if="filterCount"
                                type="button"
                                class="justify-self-start text-xs text-text-secondary hover:underline"
                                @click="clearFilters">
                                Clear filters
                            </button>
                            <button
                                v-if="canManageCategories"
                                type="button"
                                class="justify-self-start text-sm text-text-secondary hover:underline"
                                @click="
                                    filtersOpen = false;
                                    manage = true;
                                ">
                                Manage categories
                            </button>
                        </PopoverContent>
                    </Popover>
                </template>
                <slot name="actions" />
            </div>
        </div>

        <p
            v-if="historyError || categoryError"
            role="alert"
            class="py-3 text-sm text-text-secondary">
            Some history could not be loaded.
            <button v-if="historyError" type="button" class="ml-1 underline" @click="retry">
                Retry
            </button>
        </p>

        <template v-if="detailTask">
            <div class="border-b border-row-separator py-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-2">
                        <button
                            type="button"
                            :aria-label="backLabel"
                            :title="backLabel"
                            class="-ml-1 inline-grid size-8 shrink-0 place-items-center rounded-md text-text-secondary hover:bg-tertiary hover:text-text-primary"
                            @click="selectView(view)">
                            <ArrowLeftIcon class="size-4" aria-hidden="true" />
                        </button>
                        <div class="min-w-0">
                            <h2
                                ref="detailHeading"
                                tabindex="-1"
                                class="break-words text-xl font-semibold focus:outline-none">
                                {{ activeProblem?.title ?? detailTask.name }}
                            </h2>
                            <p
                                class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-sm text-text-secondary">
                                <span>{{ status(detailTask) }}</span
                                ><span v-if="detailTask.stage">{{ detailTask.stage }}</span>
                                <span v-if="detailTask.category_id">{{
                                    categoryMap.get(detailTask.category_id)?.name
                                }}</span>
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <button
                            v-if="canUpdateTasks()"
                            type="button"
                            class="history-action"
                            @click="editTaskId = detailTask.id">
                            Edit task
                        </button>
                        <button
                            type="button"
                            class="history-action"
                            @click="emit('openActivity', detailTask)">
                            Open activity
                        </button>
                    </div>
                </div>
            </div>
            <div
                v-if="relationshipTaskIds.has(detailTask.id)"
                class="border-b border-row-separator py-4">
                <h3 class="mb-3 text-sm font-medium">Task relationships</h3>
                <TaskRelationshipMap
                    :key="detailTask.id"
                    :task-id="detailTask.id"
                    :tasks="tasks"
                    :relationships="taskLinks.relationships.value"
                    @open-activity="selectTask" />
            </div>
            <ProjectHistoryTimeline :records="activeProblem?.updates ?? taskRecords" focused />
            <p
                v-if="!(activeProblem?.updates ?? taskRecords).length && !historyError"
                class="history-empty">
                {{ loading ? 'Loading activity…' : 'No activity recorded for this task.' }}
            </p>
        </template>

        <template v-else-if="view === 'timeline'">
            <ProjectHistoryTimeline :records="timeline" @select-task="selectTask" />
            <p v-if="!timeline.length && !historyError" class="history-empty">
                {{ loading ? 'Loading activity…' : 'No activity matches this view.' }}
            </p>
        </template>

        <template v-else-if="view === 'tasks'">
            <ul class="divide-y divide-row-separator">
                <li v-for="task in taskList" :key="task.id" class="flex items-center gap-1 pr-2">
                    <button
                        type="button"
                        class="flex min-w-0 flex-1 items-center justify-between gap-3 px-2 py-3 text-left text-sm hover:bg-tertiary"
                        @click="selectTask(task)">
                        <span class="min-w-0">
                            <span class="block break-words font-medium">{{ task.name }}</span>
                            <span
                                v-if="task.stage || task.category_id"
                                class="mt-1 flex flex-wrap items-center gap-x-3 text-xs text-text-secondary">
                                <span v-if="task.stage">{{ task.stage }}</span>
                                <span
                                    v-if="task.category_id"
                                    class="inline-flex items-center gap-1.5">
                                    <span
                                        class="size-2 rounded-full"
                                        :style="{
                                            background: categoryMap.get(task.category_id)?.color,
                                        }" />{{ categoryMap.get(task.category_id)?.name }}
                                </span>
                            </span>
                        </span>
                        <span class="shrink-0 text-xs text-text-secondary">{{ status(task) }}</span>
                    </button>
                    <TaskMoreOptionsDropdown
                        v-if="canUpdateTasks()"
                        :task="task"
                        edit-only
                        @edit="editTaskId = task.id" />
                </li>
            </ul>
            <p v-if="!taskList.length" class="history-empty">No tasks match this view.</p>
        </template>

        <template v-else>
            <ul class="divide-y divide-row-separator">
                <li v-for="problem in visibleProblems" :key="problem.id">
                    <button
                        type="button"
                        class="history-list-row"
                        @click="selectedProblemId = problem.id">
                        <span class="min-w-0">
                            <span class="block break-words font-medium">{{ problem.title }}</span>
                            <span
                                v-if="problem.title !== problem.task.name"
                                class="mt-1 block text-xs text-text-secondary"
                                >{{ problem.task.name }}</span
                            >
                        </span>
                        <span class="text-xs capitalize text-text-secondary">{{
                            problem.outcome ??
                            (relationshipTaskIds.has(problem.task.id) ? 'Linked work' : '')
                        }}</span>
                        <ChevronRightIcon class="size-4 text-icon-default" aria-hidden="true" />
                    </button>
                </li>
            </ul>
            <p v-if="!visibleProblems.length && !historyError" class="history-empty">
                {{ loading ? 'Loading problems…' : 'No recorded problems match this view.' }}
            </p>
        </template>
    </div>
    <TaskEditModal
        v-if="editingTask"
        :key="editingTask.id"
        :show="true"
        :task="editingTask"
        @update:show="
            (open) => {
                if (!open) editTaskId = null;
            }
        " />
    <TaskCategoryManager v-model:show="manage" />
</template>

<style scoped>
.history-control {
    height: 2rem;
    width: 100%;
    border: 1px solid var(--theme-color-default-background-separator);
    border-radius: 0.375rem;
    background-color: var(--theme-color-default-background);
    padding-block: 0.2rem;
    padding-inline-start: 0.55rem;
    padding-inline-end: 1.7rem;
    font-size: 0.8125rem;
}
.history-list-row {
    @apply grid w-full grid-cols-[minmax(0,1fr)_auto_1rem] items-center gap-4 px-2 py-3 text-left text-sm hover:bg-tertiary;
}
.history-action {
    @apply rounded-md px-2.5 py-1.5 text-text-secondary hover:bg-tertiary hover:text-text-primary;
}
.history-empty {
    @apply py-12 text-center text-sm text-text-secondary;
}
</style>
