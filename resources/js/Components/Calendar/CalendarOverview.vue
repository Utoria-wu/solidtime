<script setup lang="ts">
import { computed } from 'vue';
import { ChevronRight, Plus } from '@lucide/vue';
import type { Dayjs } from 'dayjs';
import { getLocalizedDayJs } from '@/packages/ui/src/utils/time';
import { getCalendarMonthDays } from '@/packages/ui/src/FullCalendar/useCalendarNavigation';
import { Button } from '@/packages/ui/src/Buttons';
import type { Reminder } from './personalCalendar';

type Kind = 'holiday' | 'workday' | 'reminder';

export interface CalendarOverviewItem {
    key: string;
    kind: Kind;
    title: string;
    time?: string | null;
    start: string;
    end: string;
    reminder?: Reminder;
}

const props = defineProps<{
    view: 'month' | 'year';
    date: Dayjs;
    items: Map<string, CalendarOverviewItem[]>;
    loading: boolean;
    showHolidays: boolean;
    showReminders: boolean;
}>();
const emit = defineEmits<{
    select: [date: Dayjs];
    month: [date: Dayjs];
    add: [];
    edit: [reminder: Reminder];
}>();

const labels: Record<Kind, string> = {
    holiday: 'Holiday',
    workday: 'Make-up workday',
    reminder: 'Personal reminder',
};
const sources = computed<Kind[]>(() => [
    ...(props.showHolidays ? (['holiday', 'workday'] as const) : []),
    ...(props.showReminders ? (['reminder'] as const) : []),
]);
const dayKey = (date: Dayjs) => date.format('YYYY-MM-DD');
const selected = computed(() => dayKey(props.date));
const today = computed(() => dayKey(getLocalizedDayJs()));
const agenda = computed(() => props.items.get(selected.value) ?? []);

function makeDay(date: Dayjs, month: Dayjs) {
    const key = dayKey(date);
    const inMonth = date.isSame(month, 'month');
    const kinds = inMonth
        ? [...new Set((props.items.get(key) ?? []).map((item) => item.kind))]
        : [];
    return { date, key, inMonth, kinds };
}

const monthDays = computed(() => getCalendarMonthDays(props.date));
const weekdays = computed(() => monthDays.value.slice(0, 7).map((day) => day.format('ddd')));
const monthGrid = computed(() => monthDays.value.map((date) => makeDay(date, props.date)));
const yearGrid = computed(() =>
    Array.from({ length: 12 }, (_, index) => {
        const month = props.date.startOf('year').add(index, 'month');
        return {
            month,
            days: getCalendarMonthDays(month, true).map((date) => makeDay(date, month)),
        };
    })
);
</script>

<template>
    <section class="overview-scroll" :aria-busy="loading">
        <div class="overview-content">
            <div v-if="view === 'month'" class="month-layout">
                <div class="min-w-0">
                    <div class="month-weekdays" aria-hidden="true">
                        <span v-for="weekday in weekdays" :key="weekday">{{ weekday }}</span>
                    </div>
                    <div class="month-days" role="group" aria-label="Month calendar">
                        <button
                            v-for="day in monthGrid"
                            :key="day.key"
                            type="button"
                            class="month-day"
                            :class="{ 'outside-month': !day.inMonth }"
                            :aria-label="day.date.format('dddd, MMMM D, YYYY')"
                            :aria-description="
                                day.kinds.map((kind) => labels[kind]).join(', ') || undefined
                            "
                            :aria-current="day.key === today ? 'date' : undefined"
                            :aria-pressed="day.key === selected"
                            :data-day="day.key"
                            @click="emit('select', day.date)">
                            <span class="day-number">{{ day.date.date() }}</span>
                            <span class="day-marks" aria-hidden="true">
                                <i
                                    v-for="kind in day.kinds"
                                    :key="kind"
                                    class="mark"
                                    :class="kind" />
                            </span>
                        </button>
                    </div>
                </div>

                <aside class="agenda" aria-label="Selected day">
                    <header class="agenda-header">
                        <div>
                            <p class="agenda-weekday">{{ date.format('dddd') }}</p>
                            <h2>{{ date.format('MMMM D') }}</h2>
                        </div>
                        <Button
                            size="icon"
                            variant="outline"
                            class="size-8 rounded-full shadow-none"
                            aria-label="Add reminder for selected day"
                            @click="emit('add')">
                            <Plus />
                        </Button>
                    </header>
                    <ul v-if="agenda.length" class="agenda-list">
                        <li
                            v-for="item in agenda"
                            :key="item.key"
                            :class="item.kind"
                            class="agenda-item">
                            <button
                                v-if="item.reminder"
                                type="button"
                                class="agenda-row agenda-action"
                                aria-label="Edit reminder"
                                @click="emit('edit', item.reminder)">
                                <i class="mark" aria-hidden="true" />
                                <span class="agenda-label">
                                    <span class="agenda-title">{{ item.title }}</span>
                                    <span class="agenda-meta">{{ labels[item.kind] }}</span>
                                </span>
                                <span class="agenda-time">{{ item.time ?? 'All day' }}</span>
                                <ChevronRight class="agenda-chevron" aria-hidden="true" />
                            </button>
                            <div v-else class="agenda-row">
                                <i class="mark" aria-hidden="true" />
                                <span class="agenda-label">
                                    <span class="agenda-title">{{ item.title }}</span>
                                    <span class="agenda-meta">{{ labels[item.kind] }}</span>
                                </span>
                                <span class="agenda-time">All day</span>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="agenda-empty">
                        {{ loading ? 'Loading calendar…' : 'No items for this day' }}
                    </p>
                </aside>
            </div>

            <div v-else class="year-layout" role="group" aria-label="Year calendar">
                <button
                    v-for="{ month, days } in yearGrid"
                    :key="month.month()"
                    type="button"
                    class="mini-month"
                    :class="{ 'selected-month': month.isSame(date, 'month') }"
                    :aria-label="month.format('MMMM YYYY')"
                    @click="emit('month', month)">
                    <strong>{{ month.format('MMMM') }}</strong>
                    <span class="mini-grid" aria-hidden="true">
                        <span v-for="weekday in weekdays" :key="weekday" class="mini-weekday">{{
                            weekday.slice(0, 1)
                        }}</span>
                        <span
                            v-for="day in days"
                            :key="day.key"
                            class="mini-day"
                            :data-today="(day.key === today && day.inMonth) || undefined">
                            <template v-if="day.inMonth">
                                <span>{{ day.date.date() }}</span>
                                <span class="mini-marks">
                                    <i
                                        v-for="kind in day.kinds"
                                        :key="kind"
                                        class="mark"
                                        :class="kind" />
                                </span>
                            </template>
                        </span>
                    </span>
                </button>
            </div>

            <div v-if="sources.length" class="legend" aria-label="Calendar legend">
                <span v-for="source in sources" :key="source" :class="source">
                    <i class="mark" aria-hidden="true" />{{ labels[source] }}
                </span>
            </div>
        </div>
    </section>
</template>

<style scoped>
.holiday {
    --mark: #319073;
}
.workday {
    --mark: #b58038;
}
.reminder {
    --mark: #8463c0;
}
.mark {
    width: 0.375rem;
    height: 0.375rem;
    flex: none;
    border-radius: 50%;
    background: var(--mark);
}

.overview-scroll {
    @apply min-h-0 min-w-0 flex-1 overflow-auto px-4 pb-6 pt-4 sm:px-7 sm:pt-5;
}
.overview-content {
    @apply mx-auto max-w-7xl;
}
.month-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) clamp(15rem, 25vw, 21rem);
    gap: clamp(1.5rem, 4vw, 3.5rem);
}
.month-weekdays,
.month-days {
    @apply grid grid-cols-7;
}
.month-weekdays {
    @apply mb-1 text-center text-xs font-medium text-text-secondary;
}
.month-weekdays > span {
    @apply py-2;
}
.month-day {
    @apply flex flex-col items-center justify-center gap-1.5 border-t border-default-background-separator
        transition-colors focus-visible:outline focus-visible:outline-2 -outline-offset-2 focus-visible:outline-ring;
    min-height: clamp(4rem, 9vh, 5.5rem);
}
.month-day:hover {
    @apply bg-secondary;
}
.month-day.outside-month {
    @apply text-text-tertiary;
}
.day-number {
    @apply grid size-9 place-items-center rounded-full text-base font-medium tabular-nums;
}
.month-day[aria-current='date'] .day-number {
    @apply text-accent-400 ring-1 ring-inset ring-accent-400;
}
.month-day[aria-pressed='true'] .day-number {
    @apply bg-button-primary-background text-button-primary-text ring-0;
}
.day-marks {
    @apply flex h-1.5 items-center justify-center gap-1;
}

.agenda {
    @apply min-w-0 self-start border-l border-default-background-separator pl-6;
    min-height: 10rem;
}
.agenda-header {
    @apply mb-4 flex items-end justify-between gap-3;
}
.agenda-weekday {
    @apply mb-1 text-sm text-text-secondary;
}
.agenda-header h2 {
    @apply text-2xl font-semibold tracking-tight;
}
.agenda-list {
    @apply border-t border-default-background-separator;
}
.agenda-item {
    @apply border-b border-default-background-separator;
}
.agenda-row {
    @apply flex w-full items-center gap-2.5 py-3.5 text-left;
}
.agenda-action {
    @apply rounded-sm hover:bg-secondary focus-visible:outline focus-visible:outline-2 focus-visible:outline-ring;
}
.agenda-label {
    @apply flex min-w-0 flex-1 flex-col gap-0.5;
}
.agenda-title {
    @apply break-words text-sm font-medium leading-snug;
}
.agenda-meta,
.agenda-time,
.agenda-empty {
    @apply text-xs text-text-secondary;
}
.agenda-time {
    @apply whitespace-nowrap tabular-nums;
}
.agenda-chevron {
    @apply size-3 shrink-0 text-text-secondary;
}
.agenda-empty {
    @apply border-t border-default-background-separator py-4 text-sm;
}

.year-layout {
    @apply grid grid-cols-4 gap-x-7 gap-y-5;
}
.mini-month {
    @apply min-w-0 border-t border-default-background-separator px-2 py-3 text-left
        transition-colors hover:bg-secondary focus-visible:outline focus-visible:outline-2 focus-visible:outline-ring;
}
.mini-month strong {
    @apply mb-3 block text-sm font-semibold;
}
.mini-month.selected-month strong {
    @apply text-accent-400;
}
.mini-grid {
    @apply grid grid-cols-7 text-center text-xs tabular-nums;
}
.mini-weekday {
    @apply mb-1 text-[10px] text-text-secondary;
}
.mini-day {
    @apply relative grid min-h-6 place-items-center pb-1;
}
.mini-day[data-today] {
    @apply font-semibold text-accent-400;
}
.mini-marks {
    @apply absolute inset-x-0 bottom-0.5 flex h-1 items-center justify-center gap-0.5;
}
.mini-marks .mark {
    @apply size-1;
}
.legend {
    @apply mt-5 flex flex-wrap gap-x-5 gap-y-2 text-xs text-text-secondary;
}
.legend span {
    @apply flex items-center gap-1.5;
}

@media (max-width: 980px) {
    .month-layout {
        grid-template-columns: minmax(0, 1fr);
        gap: 1.4rem;
    }
    .agenda {
        @apply border-l-0 border-t border-default-background-separator pl-0 pt-4;
    }
    .year-layout {
        @apply grid-cols-3;
    }
}
@media (max-width: 600px) {
    .month-day {
        min-height: 3.25rem;
    }
    .day-number {
        @apply size-8 text-sm;
    }
    .agenda-header h2 {
        @apply text-xl;
    }
    .year-layout {
        @apply grid-cols-2 gap-x-3 gap-y-4;
    }
    .mini-month {
        @apply px-1;
    }
    .mini-grid {
        @apply text-[10px];
    }
}
</style>
