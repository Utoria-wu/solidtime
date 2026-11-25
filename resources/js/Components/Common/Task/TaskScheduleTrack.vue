<script setup lang="ts">
import type { Task } from '@/packages/api/src';
import { computed } from 'vue';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/packages/ui/src';
import { getDayJsInstance } from '@/packages/ui/src/utils/time';

const props = defineProps<{
    dates: string[];
    today: string;
    task?: Task;
    tone?: string;
}>();

const emit = defineEmits<{ open: [] }>();
const dateLabel = computed(() => {
    const format = (date: string) => getDayJsInstance()(date).format('MMM D, YYYY');
    const start = props.task?.start_date;
    const due = props.task?.due_date;
    if (start && due && start !== due) return format(start) + ' – ' + format(due);
    if (due) return 'Due ' + format(due);
    return start ? 'Starts ' + format(start) : '';
});

const mark = computed(() => {
    const start = props.task?.start_date;
    const due = props.task?.due_date;
    const first = start || due;
    const last = due || start;
    const weekStart = props.dates[0];
    const weekEnd = props.dates.at(-1);
    if (!first || !last || !weekStart || !weekEnd || last < weekStart || first > weekEnd) {
        return null;
    }
    const startsBefore = first < weekStart;
    const endsAfter = last > weekEnd;
    return {
        from: startsBefore ? 1 : props.dates.indexOf(first) + 1,
        to: endsAfter ? props.dates.length + 1 : props.dates.indexOf(last) + 2,
        point: first === last,
        startsBefore,
        endsAfter,
        deadline: Boolean(due) && !endsAfter,
    };
});
</script>

<template>
    <div class="schedule-track" role="cell" :data-tone="tone">
        <div
            v-for="(day, index) in dates"
            :key="day"
            class="schedule-cell"
            :class="{ today: day === today }"
            :style="{ gridColumn: index + 1 }"></div>
        <Tooltip v-if="mark && task">
            <TooltipTrigger as-child>
                <button
                    type="button"
                    class="schedule-mark"
                    :aria-label="task.name + ', ' + dateLabel"
                    :class="{
                        point: mark.point,
                        deadline: mark.deadline,
                        'starts-before': mark.startsBefore,
                        'ends-after': mark.endsAfter,
                    }"
                    :style="{ gridColumn: mark.from + ' / ' + mark.to }"
                    @click.prevent.stop="emit('open')">
                    <span aria-hidden="true" class="schedule-pill" />
                </button>
            </TooltipTrigger>
            <TooltipContent :side-offset="6" class="max-w-64 px-3 py-2">
                <div class="truncate font-medium">{{ task.name }}</div>
                <div>{{ dateLabel }}</div>
            </TooltipContent>
        </Tooltip>
    </div>
</template>

<style scoped>
.schedule-track {
    --mark-color: #548678;
    --mark-start: color-mix(in oklch, var(--mark-color) 16%, transparent);
    --mark-end: color-mix(in oklch, var(--mark-color) 36%, transparent);
    display: grid;
    grid-template-columns: repeat(var(--schedule-days), minmax(0, 1fr));
    container-type: inline-size;
}
.schedule-track[data-tone='overdue'] {
    --mark-color: #ad7061;
}
.schedule-track[data-tone='upcoming'] {
    --mark-color: #817397;
}
.schedule-track[data-tone='done'] {
    --mark-color: var(--theme-color-text-secondary);
    --mark-end: color-mix(in oklch, var(--mark-color) 22%, transparent);
}
.schedule-cell,
.schedule-mark {
    grid-row: 1;
}
.schedule-cell {
    border-inline-start: 1px solid var(--schedule-line);
}
.schedule-cell:nth-child(7n + 1) {
    border-inline-start-color: var(--schedule-week-line);
}
.schedule-cell.today {
    position: relative;
}
.schedule-cell.today::after {
    content: '';
    position: absolute;
    inset: 0 auto 0 50%;
    border-inline-start: 1px dashed var(--schedule-today);
    pointer-events: none;
}
.schedule-mark {
    align-self: center;
    position: relative;
    display: grid;
    align-items: center;
    height: 30px;
    margin-inline: calc(100cqi / (2 * var(--schedule-days)) - 7px);
    border-radius: 9999px;
}
.schedule-pill {
    position: relative;
    display: block;
    height: 12px;
    border-radius: 9999px;
    background: linear-gradient(100deg, var(--mark-start), var(--mark-end));
    transition:
        height 150ms ease,
        filter 150ms ease;
}
.schedule-mark:hover .schedule-pill,
.schedule-mark:focus-visible .schedule-pill {
    height: 16px;
    filter: saturate(1.3);
}
.schedule-mark:focus-visible {
    outline: 1px solid var(--mark-color);
    outline-offset: 2px;
}
.schedule-mark.starts-before {
    margin-inline-start: 0;
}
.schedule-mark.starts-before .schedule-pill {
    background: linear-gradient(90deg, transparent, var(--mark-start) 24px, var(--mark-end));
    border-start-start-radius: 0;
    border-end-start-radius: 0;
}
.schedule-mark.ends-after {
    margin-inline-end: 0;
}
.schedule-mark.ends-after .schedule-pill {
    mask-image: linear-gradient(90deg, #000 calc(100% - 24px), transparent);
}
.schedule-mark.deadline:not(.point) .schedule-pill::after {
    content: '';
    position: absolute;
    top: 50%;
    right: 5px;
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: var(--mark-color);
    transform: translateY(-50%);
}
.schedule-mark.point {
    width: min(30px, 100%);
    justify-self: center;
    justify-items: center;
    margin-inline: 0;
}
.schedule-mark.point .schedule-pill {
    width: 8px;
    height: 8px;
    background: var(--mark-color);
    transition: scale 150ms ease;
}
.schedule-mark.point:hover .schedule-pill,
.schedule-mark.point:focus-visible .schedule-pill {
    scale: 1.3;
}
@media (prefers-reduced-motion: reduce) {
    .schedule-pill,
    .schedule-mark.point .schedule-pill {
        transition: none;
    }
}
</style>
