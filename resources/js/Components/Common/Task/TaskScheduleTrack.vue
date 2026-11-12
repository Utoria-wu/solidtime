<script setup lang="ts">
import type { Task } from '@/packages/api/src';
import { computed } from 'vue';

const props = defineProps<{
    dates: string[];
    today: string;
    task?: Task;
    tone?: string;
}>();

const mark = computed(() => {
    const start = props.task?.start_date;
    const due = props.task?.due_date;
    const first = start || due;
    const last = due || start;
    const weekStart = props.dates[0];
    const weekEnd = props.dates[6];
    if (!first || !last || !weekStart || !weekEnd || last < weekStart || first > weekEnd) {
        return null;
    }
    const startsBefore = first < weekStart;
    const endsAfter = last > weekEnd;
    return {
        from: startsBefore ? 1 : props.dates.indexOf(first) + 1,
        to: endsAfter ? 8 : props.dates.indexOf(last) + 2,
        point: first === last,
        startsBefore,
        endsAfter,
        deadline: Boolean(due) && !endsAfter,
    };
});
</script>

<template>
    <div class="schedule-track" role="cell" :data-tone="tone" aria-hidden="true">
        <div
            v-for="(day, index) in dates"
            :key="day"
            class="schedule-cell"
            :class="{ today: day === today }"
            :style="{ gridColumn: index + 1 }"></div>
        <div
            v-if="mark"
            class="schedule-mark"
            :class="{
                point: mark.point,
                deadline: mark.deadline,
                'starts-before': mark.startsBefore,
                'ends-after': mark.endsAfter,
            }"
            :style="{ gridColumn: `${mark.from} / ${mark.to}` }"></div>
    </div>
</template>

<style scoped>
.schedule-track {
    --mark-color: #548678;
    --mark-fill: color-mix(in oklch, var(--mark-color) 19%, var(--theme-color-default-background));
    --mark-end: color-mix(in oklch, var(--mark-color) 50%, var(--theme-color-default-background));
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    container-type: inline-size;
}
.schedule-track[data-tone='overdue'] {
    --mark-color: #ad7061;
}
.schedule-track[data-tone='upcoming'] {
    --mark-color: #817397;
}
.schedule-track[data-tone='done'] {
    --mark-color: var(--color-text-secondary);
    --mark-end: color-mix(in oklch, var(--mark-color) 32%, var(--theme-color-default-background));
}
.schedule-cell,
.schedule-mark {
    grid-row: 1;
}
.schedule-cell {
    background: color-mix(
        in oklch,
        var(--color-text-secondary) 3%,
        var(--theme-color-default-background)
    );
}
.schedule-cell:nth-child(n + 6) {
    background: color-mix(
        in oklch,
        var(--color-text-secondary) 7%,
        var(--theme-color-default-background)
    );
}
.schedule-cell.today {
    background: linear-gradient(
        90deg,
        color-mix(in oklch, #70b3cf 12%, var(--theme-color-default-background)),
        color-mix(in oklch, #70b3cf 21%, var(--theme-color-default-background)),
        color-mix(in oklch, #70b3cf 12%, var(--theme-color-default-background))
    );
    position: relative;
}
.schedule-cell.today::after {
    content: '';
    position: absolute;
    inset: 0 auto 0 50%;
    width: 1px;
    background: #86b0c2;
}
.schedule-mark {
    align-self: center;
    position: relative;
    height: 10px;
    margin-inline: calc(100cqi / 14);
    border-radius: 3px 0 0 3px;
    background: linear-gradient(90deg, var(--mark-fill), var(--mark-end));
}
.schedule-mark.starts-before {
    margin-inline-start: 0;
    border-radius: 0;
    background: linear-gradient(90deg, transparent, var(--mark-fill) 32px, var(--mark-end));
}
.schedule-mark.ends-after {
    margin-inline-end: 0;
    mask-image: linear-gradient(90deg, #000 calc(100% - 32px), transparent);
}
.schedule-mark.deadline:not(.point)::after {
    content: '';
    position: absolute;
    top: 50%;
    right: 0;
    width: 3px;
    height: 16px;
    border-radius: 1px;
    background: var(--mark-color);
    transform: translate(50%, -50%);
}
.schedule-mark.point {
    width: 9px;
    height: 9px;
    justify-self: center;
    margin: 0;
    border-radius: 2px;
    background: var(--mark-color);
}
.schedule-mark.point.deadline {
    transform: rotate(45deg);
}
</style>
