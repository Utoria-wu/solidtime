<script setup lang="ts">
import { computed, inject, type ComputedRef } from 'vue';
import { Button } from '@/packages/ui/src/Buttons';
import { ChevronRightIcon, PlusIcon } from '@heroicons/vue/20/solid';
import { ExclamationTriangleIcon, ArrowRightIcon } from '@heroicons/vue/16/solid';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/packages/ui/src';
import { Link } from '@inertiajs/vue3';
import TimesheetRow from '@/Components/Timesheet/TimesheetRow.vue';
import TimeTrackerProjectTaskDropdown from '@/packages/ui/src/TimeTracker/TimeTrackerProjectTaskDropdown.vue';
import { getDayJsInstance } from '@/packages/ui/src/utils/time';
import type {
    Client,
    CreateClientBody,
    CreateProjectBody,
    Organization,
    Project,
    Tag,
    Task,
} from '@/packages/api/src';
import type { TimesheetRow as TimesheetRowType, TimesheetRowKey } from '@/utils/useTimesheetGrid';
import type { CellSaveStatus } from '@/utils/timesheet/useTimesheetCellMutations';

const organization = inject<ComputedRef<Organization>>('organization');
const dayjs = getDayJsInstance();

const props = defineProps<{
    rows: TimesheetRowType[];
    expandedProjects: Set<string | null>;
    weekDays: string[];
    todayDate: string;
    dayTotals: number[];
    weekTotalFormatted: string;
    breakDayTotals: number[];
    breakGrandTotal: number;
    projects: Project[];
    tasks: Task[];
    clients: Client[];
    tags: Tag[];
    currency: string;
    canCreateProject: boolean;
    enableEstimatedTime: boolean;
    createProject: (project: CreateProjectBody) => Promise<Project | undefined>;
    createClient: (client: CreateClientBody) => Promise<Client | undefined>;
    createTag: (name: string) => Promise<Tag | undefined>;
    formatDuration: (seconds: number) => string;
    cellStatuses: Record<string, CellSaveStatus>;
    cellPendingSeconds: Record<string, number>;
    misplacedBreakDates?: Set<string>;
}>();

const emit = defineEmits<{
    (e: 'toggle-project', id: string | null): void;
    (e: 'remove-row', key: TimesheetRowKey): void;
    (e: 'cell-update', row: TimesheetRowType, dayIndex: number, seconds: number): void;
    (
        e: 'project-task-change',
        row: TimesheetRowType,
        projectId: string | null,
        taskId: string | null
    ): void;
    (e: 'billable-change', row: TimesheetRowType, billable: boolean): void;
    (e: 'tags-change', row: TimesheetRowType, tags: string[]): void;
    (e: 'add-row', projectId: string | null, taskId: string | null): void;
}>();
const groups = computed(() => {
    const byProject = new Map<string | null, TimesheetRowType[]>();
    for (const row of props.rows) {
        if (row.type === 'break') continue;
        const rows = byProject.get(row.projectId) ?? [];
        rows.push(row);
        byProject.set(row.projectId, rows);
    }
    return Array.from(byProject, ([id, rows]) => ({
        id,
        project: props.projects.find((project) => project.id === id),
        rows,
        daily: props.weekDays.map((_, index) =>
            rows.reduce((sum, row) => sum + (row.cells.get(index)?.totalSeconds ?? 0), 0)
        ),
        total: rows.reduce((sum, row) => sum + row.totalSeconds, 0),
    }));
});
const displayRows = computed(() => [
    ...groups.value.flatMap((group) => [
        { key: 'project:' + (group.id ?? 'none'), group, row: null },
        ...(props.expandedProjects.has(group.id)
            ? group.rows.map((row) => ({ key: row.key, group: null, row }))
            : []),
    ]),
    ...props.rows
        .filter((row) => row.type === 'break')
        .map((row) => ({ key: row.key, group: null, row })),
]);
</script>

<template>
    <div class="flow-root max-w-[100vw] overflow-x-auto">
        <div class="inline-block min-w-full align-middle">
            <div
                class="grid min-w-full w-max border-y border-default-background-separator"
                style="
                    grid-template-columns:
                        minmax(420px, 1fr) repeat(7, minmax(116px, 120px)) minmax(100px, auto)
                        40px;
                ">
                <!-- Header row -->
                <div
                    class="bg-background dark:bg-secondary pl-7 pr-3 py-1 text-xs text-text-tertiary md:sticky md:left-0 md:z-10">
                    Project
                </div>
                <div
                    v-for="day in weekDays"
                    :key="day"
                    data-testid="timesheet_day_header"
                    class="bg-background dark:bg-secondary px-2 py-1 text-center">
                    <div
                        class="flex items-center justify-center gap-1 text-xs font-medium text-text-secondary">
                        <span>{{ dayjs(day).format('ddd D') }}</span>
                        <DropdownMenu v-if="misplacedBreakDates?.has(day)">
                            <DropdownMenuTrigger as-child>
                                <button
                                    type="button"
                                    title="A break on this day does not align with your work entries"
                                    class="flex items-center justify-center shrink-0 rounded-full p-0.5 text-amber-500 hover:bg-amber-500/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                    <ExclamationTriangleIcon class="w-3.5 h-3.5" />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent class="min-w-[240px]" align="start">
                                <div class="px-3 py-2 space-y-1.5">
                                    <p class="text-xs text-text-secondary">
                                        A break on this day is not directly between work entries.
                                    </p>
                                    <Link
                                        :href="`/calendar?date=${day}`"
                                        class="inline-flex items-center gap-1 text-sm font-medium text-accent-400 hover:underline">
                                        Fix in calendar
                                        <ArrowRightIcon class="w-3.5 h-3.5" />
                                    </Link>
                                </div>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>
                <div
                    class="bg-background dark:bg-secondary pl-3 pr-3 py-1 text-right text-xs text-text-tertiary">
                    Total
                </div>
                <div class="bg-background dark:bg-secondary"></div>

                <!-- Data rows (break row is pinned last) -->
                <template v-for="{ key, group, row } in displayRows" :key="key">
                    <template v-if="group">
                        <div
                            class="bg-background border-t border-default-background-separator px-4 py-2 md:sticky md:left-0 md:z-10">
                            <button
                                type="button"
                                class="flex w-full items-center gap-2 rounded text-left text-sm font-semibold"
                                :aria-expanded="expandedProjects.has(group.id)"
                                @click="emit('toggle-project', group.id)">
                                <ChevronRightIcon
                                    class="size-4 shrink-0 text-text-secondary"
                                    :class="{ 'rotate-90': expandedProjects.has(group.id) }" />
                                <span
                                    v-if="group.project"
                                    class="size-2.5 shrink-0 rounded-full"
                                    :style="{ backgroundColor: group.project.color }" />
                                {{
                                    group.project?.name ??
                                    (group.id === null ? 'No project' : 'Unknown project')
                                }}
                            </button>
                        </div>
                        <div
                            v-for="(seconds, index) in group.daily"
                            :key="index"
                            class="bg-background border-t border-default-background-separator px-2 py-2 text-center text-sm tabular-nums">
                            {{ formatDuration(seconds) }}
                        </div>
                        <div
                            class="bg-background border-t border-default-background-separator px-3 py-2 text-right text-sm font-semibold tabular-nums">
                            {{ formatDuration(group.total) }}
                        </div>
                        <div class="bg-background border-t border-default-background-separator" />
                    </template>
                    <TimesheetRow
                        v-else-if="row"
                        :row="row"
                        :grouped="row.type !== 'break'"
                        :week-days="weekDays"
                        :today-date="todayDate"
                        :projects="projects"
                        :tasks="tasks"
                        :clients="clients"
                        :tags="tags"
                        :currency="currency"
                        :can-create-project="canCreateProject"
                        :enable-estimated-time="enableEstimatedTime"
                        :create-project="createProject"
                        :create-client="createClient"
                        :create-tag="createTag"
                        :format-duration="formatDuration"
                        :cell-statuses="cellStatuses"
                        :cell-pending-seconds="cellPendingSeconds"
                        @remove-row="$emit('remove-row', $event)"
                        @cell-update="
                            (dayIndex, seconds) => $emit('cell-update', row, dayIndex, seconds)
                        "
                        @project-task-change="
                            (pId, tId) => $emit('project-task-change', row, pId, tId)
                        "
                        @billable-change="(billable) => $emit('billable-change', row, billable)"
                        @tags-change="(t) => $emit('tags-change', row, t)" />
                </template>

                <!-- Add row -->
                <div
                    class="col-span-full flex items-center gap-2 border-t border-default-background-separator pl-4 pr-4 py-2">
                    <TimeTrackerProjectTaskDropdown
                        :project="null"
                        :task="null"
                        :projects="projects"
                        :tasks="tasks"
                        :clients="clients"
                        :currency="currency"
                        :can-create-project="canCreateProject"
                        :enable-estimated-time="enableEstimatedTime"
                        :create-project="createProject"
                        :create-client="createClient"
                        :organization-billable-rate="organization?.billable_rate ?? null"
                        :no-project-value="null"
                        align="start"
                        @changed="(p: string | null, t: string | null) => emit('add-row', p, t)">
                        <template #trigger>
                            <Button variant="ghost" size="sm" class="text-text-secondary">
                                <PlusIcon class="h-4 w-4 mr-1 text-icon-default" />
                                Add row
                            </Button>
                        </template>
                    </TimeTrackerProjectTaskDropdown>
                </div>

                <!-- Totals row: worked time, with break time annotated below (calendar-style) -->
                <div
                    class="flex items-center border-t border-default-background-separator bg-background dark:bg-secondary pl-7 pr-3 py-1 text-xs text-text-tertiary md:sticky md:left-0 md:z-10">
                    Total
                </div>
                <div
                    v-for="(total, dayIndex) in dayTotals"
                    :key="dayIndex"
                    data-testid="timesheet_day_total"
                    :class="[
                        'flex flex-col items-center justify-center border-t border-default-background-separator bg-background dark:bg-secondary px-2 py-1 text-xs font-medium leading-tight',
                        weekDays[dayIndex] === todayDate
                            ? 'text-text-primary'
                            : 'text-text-secondary',
                    ]">
                    <span
                        >{{ formatDuration(total)
                        }}<template v-if="total > 0 && (breakDayTotals[dayIndex] ?? 0) > 0">
                            work</template
                        ></span
                    >
                    <span
                        v-if="(breakDayTotals[dayIndex] ?? 0) > 0"
                        class="font-normal text-text-tertiary"
                        >{{ formatDuration(breakDayTotals[dayIndex] ?? 0) }} break</span
                    >
                </div>
                <div
                    class="flex flex-col items-end justify-center border-t border-default-background-separator bg-background dark:bg-secondary pl-3 pr-3 py-1 text-xs font-semibold text-text-primary leading-tight">
                    <span
                        >{{ weekTotalFormatted
                        }}<template v-if="breakGrandTotal > 0"> work</template></span
                    >
                    <span v-if="breakGrandTotal > 0" class="font-normal text-text-tertiary"
                        >{{ formatDuration(breakGrandTotal) }} break</span
                    >
                </div>
                <div
                    class="border-t border-default-background-separator bg-background dark:bg-secondary"></div>
            </div>
        </div>
    </div>
</template>
