<script setup lang="ts">
import { computed, ref } from 'vue';
import { ArrowTopRightOnSquareIcon } from '@heroicons/vue/20/solid';
import { getLocalizedDayJs } from '@/packages/ui/src/utils/time';
import { renderNotes } from '@/packages/ui/src/utils/notes';
import type { Task } from '@/packages/api/src';
import type { HistoryEntry } from '@/utils/useTaskHistory';

export type ProjectHistoryRecord = {
    id: string;
    task: Task | null;
    type: 'created' | 'completed' | 'calendar' | 'record';
    title: string;
    label: string;
    date: string;
    note: string | null;
    color: string;
    calendarUrl?: string;
    legacy?: HistoryEntry;
};

const props = defineProps<{ records: ProjectHistoryRecord[]; focused?: boolean }>();
const emit = defineEmits<{ selectTask: [task: Task] }>();
const expanded = ref(new Set<string>());
const recordIds = computed(() => new Set(props.records.map((record) => record.id)));
const days = computed(() => {
    const result = new Map<string, ProjectHistoryRecord[]>();
    for (const record of props.records) {
        const key = getLocalizedDayJs(record.date).format('YYYY-MM-DD');
        const day = result.get(key) ?? [];
        day.push(record);
        result.set(key, day);
    }
    return result;
});

function toggleNote(id: string) {
    if (expanded.value.has(id)) expanded.value.delete(id);
    else expanded.value.add(id);
}

function longNote(note: string) {
    return note.length > 220 || note.split('\n').length > 3;
}
</script>

<template>
    <section v-for="[date, day] in days" :key="date" class="history-day">
        <h3 class="history-date text-sm font-medium text-text-secondary">
            <time :datetime="date">{{
                getLocalizedDayJs(day[0]!.date).format('ddd, MMM D, YYYY')
            }}</time>
        </h3>
        <div class="history-events">
            <article
                v-for="record in day"
                :id="'history-' + record.id"
                :key="record.id"
                class="history-event"
                :style="{ '--history-accent': record.color }">
                <div
                    class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-xs text-text-secondary">
                    <time class="tabular-nums" :datetime="record.date">{{
                        getLocalizedDayJs(record.date).format('HH:mm')
                    }}</time>
                    <span>{{ record.label }}</span>
                    <span v-if="record.legacy?.outcome" class="capitalize">{{
                        record.legacy.outcome
                    }}</span>
                </div>
                <div class="mt-1 flex flex-wrap items-baseline gap-x-3 gap-y-1 break-words text-sm">
                    <button
                        v-if="record.task && !focused && record.title === record.task.name"
                        type="button"
                        class="text-left font-medium hover:underline"
                        @click="emit('selectTask', record.task)">
                        {{ record.title }}
                    </button>
                    <span
                        v-else-if="!focused || record.title !== record.task?.name"
                        class="font-medium"
                        >{{ record.title }}</span
                    >
                    <button
                        v-if="record.task && !focused && record.title !== record.task.name"
                        type="button"
                        class="text-left text-xs text-text-secondary hover:underline"
                        @click="emit('selectTask', record.task)">
                        {{ record.task.name }}
                    </button>
                    <a
                        v-if="record.calendarUrl"
                        :href="record.calendarUrl"
                        class="inline-flex items-center gap-1 text-xs text-text-secondary hover:underline">
                        Open in Calendar
                        <ArrowTopRightOnSquareIcon class="size-3" aria-hidden="true" />
                    </a>
                    <a
                        v-if="record.legacy?.parent_id && recordIds.has(record.legacy.parent_id)"
                        :href="'#history-' + record.legacy.parent_id"
                        class="text-xs text-text-secondary hover:underline">
                        Previous record
                    </a>
                </div>
                <div
                    v-if="record.note"
                    :id="'history-note-' + record.id"
                    class="history-note mt-2 break-words text-sm text-text-secondary"
                    :class="{ 'line-clamp-3': longNote(record.note) && !expanded.has(record.id) }"
                    v-html="renderNotes(record.note)" />
                <button
                    v-if="record.note && longNote(record.note)"
                    type="button"
                    class="mt-1 text-xs font-medium text-text-secondary underline underline-offset-2"
                    :aria-expanded="expanded.has(record.id)"
                    :aria-controls="'history-note-' + record.id"
                    @click="toggleNote(record.id)">
                    {{ expanded.has(record.id) ? 'Show less' : 'Read full note' }}
                </button>
            </article>
        </div>
    </section>
</template>

<style scoped>
.history-day {
    display: grid;
    grid-template-columns: 10.5rem minmax(0, 1fr);
    gap: 1rem;
    padding-block: 1.05rem;
}
.history-day + .history-day {
    border-top: 1px solid var(--theme-color-row-separator-background);
}
.history-date {
    margin-top: 0.12rem;
}
.history-events {
    border-inline-start: 1px solid var(--theme-color-row-separator-background);
}
.history-event {
    position: relative;
    padding: 0 0 1rem 1.15rem;
}
.history-event::before {
    content: '';
    position: absolute;
    inset-inline-start: -4px;
    inset-block-start: 0.32rem;
    height: 7px;
    width: 7px;
    border-radius: 50%;
    background: var(--history-accent);
}
.history-event:last-child {
    padding-bottom: 0;
}
.history-note :deep(p + p) {
    margin-top: 0.5rem;
}
.history-note :deep(ul),
.history-note :deep(ol) {
    margin-inline-start: 1.25rem;
    list-style: revert;
}
.history-note :deep(a) {
    text-decoration: underline;
}
.history-note :deep(pre) {
    overflow: auto;
}
@media (max-width: 640px) {
    .history-day {
        grid-template-columns: minmax(0, 1fr);
        gap: 0.75rem;
    }
    .history-events {
        margin-inline-start: 0.25rem;
    }
}
</style>
