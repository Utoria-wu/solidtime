<script setup lang="ts">
import { computed, shallowRef, ref, watch } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import type { Dayjs } from 'dayjs';
import type { Project, TimeEntry } from '@/packages/api/src';
import TaskActivityDialog from '@/Components/Common/Task/TaskActivityDialog.vue';
import { Button } from '@/packages/ui/src/Buttons';
import DateRangeNavigator from '@/packages/ui/src/Input/DateRangeNavigator.vue';
import LoadingSpinner from '@/packages/ui/src/LoadingSpinner.vue';
import {
    firstDayIndex,
    getDayJsInstance,
    getLocalizedDateFromTimestamp,
    localDateToUtc,
} from '@/packages/ui/src/utils/time';
import { getDateRangeLabel } from '@/packages/ui/src/utils/dateRange';
import { getCurrentMembershipId, getCurrentOrganizationId } from '@/utils/useUser';
import { fetchTimesheetEntries } from '@/utils/useTimesheetQuery';
import type { TimesheetView } from './TimesheetViewPicker.vue';

const props = defineProps<{
    view: TimesheetView;
    weekStart: Dayjs;
    todayDate: string;
    projects: Project[];
    formatDuration: (seconds: number) => string;
    now?: Dayjs;
}>();

const emit = defineEmits<{
    week: [start: Dayjs, projectId: string | null];
    month: [];
}>();

const dayjs = getDayJsInstance();
const anchor = shallowRef(props.view === 'months' ? dayjs(props.todayDate) : props.weekStart);
const monthScope = ref(false);
const activityOpen = ref(false);
const activity = shallowRef<{
    projectName: string;
    projectColor: string;
    dateLabel: string;
    entries: TimeEntry[];
}>();
watch(
    () => props.view,
    (view) => {
        if (view !== 'weeks') monthScope.value = false;
    }
);

function startOfWeek(date: Dayjs) {
    return date.subtract((date.day() - firstDayIndex.value + 7) % 7, 'day').startOf('day');
}

const periods = computed(() => {
    if (props.view === 'months') {
        const monthStart = anchor.value.startOf('month');
        return Array.from({ length: monthStart.daysInMonth() }, (_, index) => {
            const start = monthStart.add(index, 'day');
            return { start, end: start.add(1, 'day'), week: null };
        });
    }
    if (props.view === 'weeks') {
        if (!monthScope.value) {
            const first = startOfWeek(anchor.value).subtract(11, 'week');
            return Array.from({ length: 12 }, (_, index) => {
                const start = first.add(index, 'week');
                return { start, end: start.add(1, 'week'), week: start };
            });
        }
        const monthStart = anchor.value.startOf('month');
        const monthEnd = monthStart.add(1, 'month');
        const result = [];
        for (
            let week = startOfWeek(monthStart);
            week.isBefore(monthEnd, 'day');
            week = week.add(1, 'week')
        ) {
            const next = week.add(1, 'week');
            result.push({
                start: week.isBefore(monthStart, 'day') ? monthStart : week,
                end: next.isAfter(monthEnd, 'day') ? monthEnd : next,
                week,
            });
        }
        return result;
    }

    const first = anchor.value.startOf('year');
    return Array.from({ length: 12 }, (_, index) => {
        const start = first.add(index, 'month');
        return { start, end: start.add(1, 'month'), week: null };
    });
});

const range = computed(() => ({ start: periods.value[0]!.start, end: periods.value.at(-1)!.end }));
const rangeLabel = computed(() =>
    getDateRangeLabel(range.value.start, range.value.end.subtract(1, 'day'))
);
const queryKey = computed(
    () =>
        [
            'timeEntries',
            'timesheet-project-history',
            getCurrentOrganizationId(),
            getCurrentMembershipId(),
            localDateToUtc(range.value.start),
            localDateToUtc(range.value.end),
        ] as const
);

const { data, isPending, isError, refetch } = useQuery({
    queryKey,
    enabled: computed(() => Boolean(getCurrentOrganizationId() && getCurrentMembershipId())),
    queryFn: ({ queryKey: [, , organization, member, start, end] }) =>
        fetchTimesheetEntries(organization!, member!, start, end),
    staleTime: 30_000,
});

const rows = computed(() => {
    const byProject = new Map<string | null, { values: number[]; entries: TimeEntry[][] }>();
    const boundaries = periods.value.map(({ start, end }) => [
        start.format('YYYY-MM-DD'),
        end.format('YYYY-MM-DD'),
    ]);
    for (const entry of data.value?.data ?? []) {
        if (entry.type === 'break') continue;
        const date = getLocalizedDateFromTimestamp(entry.start);
        const index = boundaries.findIndex(([start, end]) => date >= start! && date < end!);
        if (index === -1) continue;
        const seconds =
            entry.end === null
                ? Math.max(0, (props.now ?? dayjs.utc()).diff(dayjs.utc(entry.start), 'second'))
                : (entry.duration ?? 0);
        if (seconds <= 0 && props.view !== 'months') continue;
        const project = byProject.get(entry.project_id) ?? {
            values: periods.value.map(() => 0),
            entries: periods.value.map(() => []),
        };
        project.values[index]! += Math.max(0, seconds);
        project.entries[index]!.push(entry);
        byProject.set(entry.project_id, project);
    }
    return Array.from(byProject, ([id, { values, entries }]) => {
        const project = props.projects.find((project) => project.id === id);
        return {
            id,
            name: project?.name ?? (id === null ? 'No project' : 'Unknown project'),
            color: project?.color ?? '#64748b',
            values,
            entries,
            total: values.reduce((sum, seconds) => sum + seconds, 0),
        };
    }).sort((a, b) => a.name.localeCompare(b.name));
});
const totals = computed(() =>
    periods.value.map((_, index) =>
        rows.value.reduce((sum, row) => sum + (row.values[index] ?? 0), 0)
    )
);
const total = computed(() => totals.value.reduce((sum, seconds) => sum + seconds, 0));
const max = computed(() =>
    rows.value.reduce((highest, row) => Math.max(highest, ...row.values), 1)
);

function displayDuration(seconds: number) {
    return seconds > 0 && seconds < 60 ? '<1 min' : props.formatDuration(seconds);
}

function isCurrentPeriod(start: Dayjs, end: Dayjs) {
    return (
        props.todayDate >= start.format('YYYY-MM-DD') && props.todayDate < end.format('YYYY-MM-DD')
    );
}

function navigate(direction: number) {
    if (props.view === 'year') anchor.value = anchor.value.add(direction, 'year');
    else if (monthScope.value) anchor.value = anchor.value.add(direction, 'month');
    else if (props.view === 'months') anchor.value = anchor.value.add(direction, 'month');
    else anchor.value = anchor.value.add(direction * 12, 'week');
}

function selectPeriod(
    period: (typeof periods.value)[number],
    row: (typeof rows.value)[number],
    index: number
) {
    if (props.view === 'months') {
        activity.value = {
            projectName: row.name,
            projectColor: row.color,
            dateLabel: period.start.format('dddd, MMM D, YYYY'),
            entries: row.entries[index] ?? [],
        };
        activityOpen.value = true;
        return;
    }
    if (period.week) {
        emit('week', period.week, row.id);
        return;
    }
    anchor.value = period.start;
    monthScope.value = true;
    emit('month');
}
</script>

<template>
    <TaskActivityDialog v-if="activity" v-model:open="activityOpen" :activity="activity" />
    <div class="flex flex-wrap items-center justify-between gap-4 mb-4 px-2 sm:px-4 lg:px-6">
        <div class="flex flex-wrap items-center gap-3">
            <DateRangeNavigator
                :label="rangeLabel"
                trigger-aria-label="Return to current period"
                @previous="navigate(-1)"
                @next="navigate(1)"
                @select="
                    anchor = dayjs(todayDate);
                    monthScope = false;
                " />
            <slot />
        </div>
        <div v-if="!isPending && !isError" class="flex items-center gap-2 text-sm font-semibold">
            <span class="text-xs font-normal uppercase text-text-tertiary">Period total</span>
            {{ total ? displayDuration(total) : '0h' }}
        </div>
    </div>

    <div v-if="isPending" class="flex justify-center py-12"><LoadingSpinner /></div>
    <div v-else-if="isError" role="alert" class="px-6 py-8 text-sm">
        Could not load project hours.
        <Button variant="ghost" @click="refetch()">Retry</Button>
    </div>
    <div v-else-if="!rows.length" class="px-6 py-12 text-center text-sm text-text-secondary">
        No project hours in this period.
    </div>
    <div v-else class="overflow-x-auto max-w-[100vw]">
        <table
            class="w-full border-y border-default-background-separator text-sm"
            style="min-width: 70rem">
            <thead class="bg-background text-xs text-text-secondary">
                <tr>
                    <th
                        scope="col"
                        class="px-4 py-2 text-left font-medium"
                        :class="{ 'sticky left-0 z-10 bg-background': view === 'months' }"
                        style="min-width: 13rem">
                        Project
                    </th>
                    <th
                        v-for="period in periods"
                        :key="period.start.format()"
                        scope="col"
                        :aria-current="
                            isCurrentPeriod(period.start, period.end) ? 'date' : undefined
                        "
                        :class="{ 'bg-secondary': isCurrentPeriod(period.start, period.end) }"
                        class="px-1 py-2 text-center font-medium whitespace-nowrap">
                        {{
                            period.start.format(
                                view === 'weeks' ? 'MMM D' : view === 'year' ? 'MMM' : 'D'
                            )
                        }}
                    </th>
                    <th scope="col" class="px-4 py-2 text-right font-medium">Total</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="row in rows"
                    :key="row.id ?? 'no-project'"
                    class="border-t border-default-background-separator">
                    <th
                        scope="row"
                        class="px-4 py-3 text-left font-medium"
                        :class="{ 'sticky left-0 z-10 bg-default-background': view === 'months' }">
                        <span class="flex items-center gap-2">
                            <span
                                class="size-2.5 shrink-0 rounded-full"
                                :style="{ backgroundColor: row.color }" />
                            {{ row.name }}
                        </span>
                    </th>
                    <td
                        v-for="(period, index) in periods"
                        :key="period.start.format()"
                        class="px-0.5 py-2 text-center"
                        style="min-width: 4.2rem">
                        <button
                            v-if="view === 'months' || row.values[index]"
                            type="button"
                            class="w-full rounded-sm py-1 text-xs font-medium tabular-nums hover:ring-1 focus-visible:ring-2 ring-ring"
                            :class="{ 'bg-background text-text-tertiary': !row.values[index] }"
                            :style="
                                row.values[index]
                                    ? {
                                          backgroundColor:
                                              'color-mix(in srgb, ' +
                                              row.color +
                                              ' ' +
                                              (16 + (24 * row.values[index]!) / max) +
                                              '%, transparent)',
                                      }
                                    : undefined
                            "
                            :aria-label="
                                row.name +
                                ', ' +
                                getDateRangeLabel(period.start, period.end.subtract(1, 'day')) +
                                ', ' +
                                (row.values[index]
                                    ? displayDuration(row.values[index]!)
                                    : 'No tracked time')
                            "
                            @click="selectPeriod(period, row, index)">
                            {{ row.values[index] ? displayDuration(row.values[index]!) : '—' }}
                        </button>
                        <span
                            v-else
                            class="block rounded-sm bg-background py-1 text-xs text-text-tertiary"
                            aria-label="No tracked time"
                            >—</span
                        >
                    </td>
                    <td class="px-4 py-3 text-right font-semibold whitespace-nowrap">
                        {{ displayDuration(row.total) }}
                    </td>
                </tr>
            </tbody>
            <tfoot class="border-t border-default-background-separator bg-background text-xs">
                <tr>
                    <th
                        scope="row"
                        class="px-4 py-2 text-left font-medium"
                        :class="{ 'sticky left-0 z-10 bg-background': view === 'months' }">
                        Total
                    </th>
                    <td
                        v-for="(seconds, index) in totals"
                        :key="index"
                        class="px-1 py-2 text-center whitespace-nowrap">
                        {{ displayDuration(seconds) }}
                    </td>
                    <td class="px-4 py-2 text-right font-semibold whitespace-nowrap">
                        {{ displayDuration(total) }}
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</template>
