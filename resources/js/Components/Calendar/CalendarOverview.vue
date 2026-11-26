<script setup lang="ts">
import { computed } from 'vue';
import { ArrowUpRight, Plus } from '@lucide/vue';
import type { Dayjs } from 'dayjs';
import { getLocalizedDayJs } from '@/packages/ui/src/utils/time';
import { getCalendarMonthDays } from '@/packages/ui/src/FullCalendar/useCalendarNavigation';
import { Button } from '@/packages/ui/src/Buttons';
import type { Reminder } from './personalCalendar';

type Kind = 'holiday' | 'workday' | 'reminder' | 'entry';
export interface CalendarOverviewItem {
    key: string;
    kind: Kind;
    title: string;
    time?: string | null;
    start: string;
    end: string;
    reminder?: Reminder;
    entryId?: string;
}

const props = defineProps<{
    view: 'month' | 'year';
    date: Dayjs;
    items: Map<string, CalendarOverviewItem[]>;
    loading: boolean;
    showEntries: boolean;
    showHolidays: boolean;
    showReminders: boolean;
}>();
const emit = defineEmits<{
    select: [date: Dayjs];
    month: [date: Dayjs];
    add: [];
    edit: [reminder: Reminder];
    entry: [id: string, date: Dayjs];
}>();

const labels: Record<Kind, string> = {
    holiday: 'Holiday',
    workday: 'Make-up workday',
    reminder: 'Personal reminder',
    entry: 'Time entry',
};
const sources = computed(
    () =>
        [
            { kind: 'holiday', visible: props.showHolidays },
            { kind: 'workday', visible: props.showHolidays },
            { kind: 'reminder', visible: props.showReminders },
            { kind: 'entry', visible: props.showEntries },
        ].filter((source) => source.visible) as { kind: Kind }[]
);

const dayKey = (date: Dayjs) => date.format('YYYY-MM-DD');
const monthDays = computed(() => getCalendarMonthDays(props.date));
const weekdays = computed(() => monthDays.value.slice(0, 7).map((day) => day.format('ddd')));
const selected = computed(() => dayKey(props.date));
const today = computed(() => dayKey(getLocalizedDayJs()));
const agenda = computed(() => props.items.get(selected.value) ?? []);
const months = computed(() =>
    Array.from({ length: 12 }, (_, i) => props.date.startOf('year').add(i, 'month'))
);
const yearGrid = computed(() =>
    months.value.map((month) => ({
        month,
        days: getCalendarMonthDays(month, true).map((date) => {
            if (!date.isSame(month, 'month')) return { date, label: '', kinds: [] as Kind[] };
            const kinds = [
                ...new Set((props.items.get(dayKey(date)) ?? []).map((item) => item.kind)),
            ];
            return { date, label: String(date.date()), kinds };
        }),
    }))
);
const monthGrid = computed(() => {
    const days = monthDays.value;
    const cells = [];
    for (let week = 0; week < days.length; week += 7) {
        const weekDays = days.slice(week, week + 7);
        const weekKeys = weekDays.map(dayKey);
        const lanes = new Map<string, number>();
        const slots = weekDays.map(() => Array<CalendarOverviewItem | null>(2).fill(null));
        weekKeys.forEach((key, column) => {
            (props.items.get(key) ?? []).forEach((item) => {
                if (item.kind !== 'holiday' && item.kind !== 'workday') return;
                let lane = lanes.get(item.key);
                if (lane === undefined) {
                    lane = slots[column]!.indexOf(null);
                    lanes.set(item.key, lane);
                }
                if (lane >= 0) slots[column]![lane] = item;
            });
        });
        cells.push(
            ...weekDays.map((date, column) => {
                const key = weekKeys[column]!;
                const items = props.items.get(key) ?? [];
                const row = slots[column]!;
                const events = row.slice(0, row[1] ? 2 : row[0] ? 1 : 0);
                events.push(
                    ...items.filter((item) => item.kind === 'entry' || item.kind === 'reminder')
                );
                const shown = events.slice(0, 2);
                return {
                    date,
                    key,
                    extra: items.length - shown.filter(Boolean).length,
                    events: shown.map((item) => ({
                        item,
                        left:
                            item &&
                            (item.kind === 'holiday' || item.kind === 'workday') &&
                            column > 0 &&
                            item.start < key,
                        right:
                            item &&
                            (item.kind === 'holiday' || item.kind === 'workday') &&
                            column < 6 &&
                            item.end > weekKeys[column + 1]!,
                    })),
                };
            })
        );
    }
    return cells;
});
</script>

<template>
    <section
        class="min-h-0 min-w-0 flex-1 overflow-auto px-3 pt-3 pb-6 sm:px-6"
        :aria-busy="loading">
        <div v-if="view === 'month'" class="month-layout">
            <div class="min-w-0">
                <div class="month-board">
                    <div class="weekdays">
                        <span v-for="weekday in weekdays" :key="weekday">{{ weekday }}</span>
                    </div>
                    <div class="month-days" role="group" aria-label="Month calendar">
                        <button
                            v-for="cell in monthGrid"
                            :key="cell.key"
                            type="button"
                            class="day"
                            :class="{
                                weekend: cell.date.day() === 0 || cell.date.day() === 6,
                                outside: !cell.date.isSame(date, 'month'),
                            }"
                            :aria-label="cell.date.format('dddd, MMMM D, YYYY')"
                            :aria-current="cell.key === today ? 'date' : undefined"
                            :aria-pressed="cell.key === selected"
                            :data-day="cell.key"
                            @click="emit('select', cell.date)">
                            <span class="day-number">{{ cell.date.date() }}</span>
                            <template v-for="(event, i) in cell.events" :key="event.item?.key ?? i">
                                <span
                                    v-if="event.item"
                                    class="event"
                                    :class="event.item.kind"
                                    :data-continues-left="event.left || undefined"
                                    :data-continues-right="event.right || undefined"
                                    :title="event.item.title">
                                    <span v-if="!event.left">
                                        <span
                                            v-if="event.item.time"
                                            class="mr-1 tabular-nums text-text-secondary"
                                            >{{ event.item.time }}</span
                                        >{{ event.item.title }}
                                    </span>
                                </span>
                                <span v-else class="event event-space" aria-hidden="true" />
                            </template>
                            <span
                                v-if="cell.extra > 0"
                                class="mt-0.5 text-[10px] text-text-secondary"
                                >+{{ cell.extra }}</span
                            >
                        </button>
                    </div>
                </div>
                <div v-if="sources.length" class="legend" aria-label="Calendar legend">
                    <span v-for="source in sources" :key="source.kind" :class="source.kind">
                        <i class="source-dot" aria-hidden="true" />{{ labels[source.kind] }}
                    </span>
                </div>
            </div>

            <aside class="min-w-0 py-1" aria-label="Selected day">
                <p class="mb-0.5 text-sm text-text-secondary">{{ date.format('ddd') }}</p>
                <h2 class="text-2xl font-semibold tracking-tight">{{ date.format('MMMM D') }}</h2>
                <ul v-if="agenda.length" class="mt-4 border-y border-border">
                    <li
                        v-for="item in agenda"
                        :key="item.key"
                        class="flex items-start gap-2.5 border-b border-border py-3 last:border-b-0"
                        :class="item.kind">
                        <i class="source-dot mt-1.5" aria-hidden="true" />
                        <div class="min-w-0 flex-1 break-words">
                            <p class="text-sm font-medium">{{ item.title }}</p>
                            <p class="mt-0.5 text-xs text-text-secondary">
                                {{ item.time ?? 'All day' }} · {{ labels[item.kind] }}
                            </p>
                        </div>
                        <Button
                            v-if="item.reminder"
                            size="xs"
                            variant="ghost"
                            aria-label="Edit reminder"
                            @click="emit('edit', item.reminder)"
                            >Edit</Button
                        >
                        <Button
                            v-else-if="item.entryId"
                            size="xs"
                            variant="ghost"
                            aria-label="Open time entry in day view"
                            @click="emit('entry', item.entryId, date)">
                            <ArrowUpRight />
                        </Button>
                    </li>
                </ul>
                <p v-else class="mt-4 border-t border-border py-3 text-sm text-text-secondary">
                    {{ loading ? 'Loading calendar…' : 'No items for this day' }}
                </p>
                <Button
                    size="sm"
                    variant="ghost"
                    class="mt-2 px-0 text-accent-400 hover:underline"
                    @click="emit('add')">
                    <Plus /> Reminder
                </Button>
            </aside>
        </div>

        <template v-else>
            <div class="year-layout" aria-label="Year calendar">
                <button
                    v-for="{ month, days } in yearGrid"
                    :key="month.month()"
                    type="button"
                    class="mini-month"
                    :class="{ 'selected-month': month.isSame(date, 'month') }"
                    :aria-label="month.format('MMMM YYYY')"
                    @click="emit('month', month)">
                    <strong class="text-sm font-medium">{{ month.format('MMMM') }}</strong>
                    <span class="mini-grid" aria-hidden="true">
                        <span
                            v-for="weekday in weekdays"
                            :key="weekday"
                            class="text-text-secondary"
                            >{{ weekday.slice(0, 1) }}</span
                        >
                        <span
                            v-for="day in days"
                            :key="dayKey(day.date)"
                            class="mini-day"
                            :class="day.kinds[0]">
                            {{ day.label }}
                            <i
                                v-if="day.kinds[0] !== 'reminder' && day.kinds.includes('reminder')"
                                class="source-dot reminder" />
                        </span>
                    </span>
                </button>
            </div>
            <div v-if="sources.length" class="legend" aria-label="Calendar legend">
                <span v-for="source in sources" :key="source.kind" :class="source.kind">
                    <i class="source-dot" aria-hidden="true" />{{ labels[source.kind] }}
                </span>
            </div>
        </template>
    </section>
</template>

<style scoped>
.holiday {
    --mark: #62a389;
}
.workday {
    --mark: #c99740;
}
.reminder {
    --mark: #9577c0;
}
.entry {
    --mark: #668fb6;
}
.month-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) clamp(14rem, 19vw, 18rem);
    gap: clamp(1rem, 2vw, 1.6rem);
}
.month-board {
    @apply overflow-hidden rounded-xl border border-border bg-card-background;
}
.weekdays,
.month-days {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
}
.weekdays {
    @apply border-b border-border text-xs font-medium text-text-secondary;
}
.weekdays > span {
    @apply px-2.5 py-2.5;
}
.day {
    --day-pad: 0.45rem;
    display: flex;
    flex-direction: column;
    min-height: clamp(5rem, 11vh, 7rem);
    padding: 0.55rem var(--day-pad);
    @apply min-w-0 border-b border-r border-border text-left transition-colors;
}
.day:nth-child(7n) {
    border-inline-end: 0;
}
.day:nth-last-child(-n + 7) {
    border-bottom: 0;
}
.day.weekend {
    @apply bg-default-background;
}
.day.outside .day-number {
    @apply text-text-secondary;
}
.day:hover {
    background: color-mix(in srgb, #468fb7 6%, transparent);
}
.day[aria-pressed='true'] {
    background: color-mix(in srgb, #468fb7 11%, transparent);
}
.day:focus-visible {
    @apply z-10 outline outline-2 -outline-offset-2 outline-ring;
}
.day-number {
    @apply mb-1 grid size-6 place-items-center self-start rounded-full text-xs font-semibold tabular-nums;
}
.day[aria-current='date'] .day-number {
    @apply ring-1 ring-inset ring-ring;
}
.day[aria-pressed='true'] .day-number {
    @apply bg-button-primary-background text-button-primary-text;
}
.event {
    height: 1.38rem;
    background: color-mix(in srgb, var(--mark) 24%, transparent);
    @apply relative mt-0.5 block truncate rounded px-1.5 text-[11px] leading-[1.38rem];
}
.event-space {
    visibility: hidden;
}
.event[data-continues-left] {
    margin-inline-start: calc(-1 * var(--day-pad));
    border-start-start-radius: 0;
    border-end-start-radius: 0;
}
.event[data-continues-right] {
    margin-inline-end: calc(-1 * var(--day-pad) - 1px);
    border-start-end-radius: 0;
    border-end-end-radius: 0;
    z-index: 1;
}
.legend {
    @apply mt-3.5 flex flex-wrap gap-x-4 gap-y-1 text-xs text-text-secondary;
}
.legend > span {
    @apply flex items-center gap-1.5;
}
.source-dot {
    background: var(--mark);
    @apply inline-block size-1.5 shrink-0 rounded-full;
}
.year-layout {
    @apply grid grid-cols-4 gap-4;
}
.mini-month {
    @apply block rounded-xl border border-border bg-card-background p-3 text-left transition-colors hover:border-ring focus-visible:outline focus-visible:outline-2 focus-visible:outline-ring;
}
.mini-month.selected-month {
    border-color: color-mix(in srgb, #468fb7 55%, transparent);
    background: color-mix(in srgb, #468fb7 5%, transparent);
}
.mini-grid {
    @apply mt-2 grid grid-cols-7 gap-0.5 text-center text-[11px] tabular-nums;
}
.mini-day {
    @apply relative grid min-h-5 place-items-center rounded;
    background: color-mix(in srgb, var(--mark, transparent) 25%, transparent);
}
.mini-day .source-dot {
    @apply absolute right-0.5 top-0.5 size-1;
}
@media (max-width: 980px) {
    .month-layout {
        grid-template-columns: minmax(0, 1fr);
    }
    .year-layout {
        @apply grid-cols-3;
    }
}
@media (max-width: 640px) {
    .year-layout {
        @apply grid-cols-2 gap-2;
    }
    .mini-month {
        @apply p-2;
    }
    .weekdays > span {
        @apply px-1;
    }
    .day {
        --day-pad: 0.2rem;
        min-height: 3.7rem;
    }
    .event {
        height: 0.32rem;
        padding: 0;
        font-size: 0;
    }
}
</style>
