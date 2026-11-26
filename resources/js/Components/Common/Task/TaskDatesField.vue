<script setup lang="ts">
import { computed, inject, ref, shallowRef, useId, type ComputedRef } from 'vue';
import { parseDate, type DateValue } from '@internationalized/date';
import { Button } from '@/packages/ui/src/Buttons';
import { Field, FieldLabel } from '@/packages/ui/src/field';
import { ArrowRight, CalendarIcon } from '@lucide/vue';
import { Popover, PopoverAnchor, PopoverContent } from '@/packages/ui/src/popover';
import { Calendar, CalendarCellTrigger } from '@/packages/ui/src/calendar';
import { useInterfaceLanguage } from '@/utils/useInterfaceLanguage';
import {
    firstDayIndex,
    formatDate,
    getLocalizedDayJs,
    type WeekStartDay,
} from '@/packages/ui/src/utils/time';
import type { Organization } from '@/packages/api/src';

type TaskDates = { start_date: string | null; due_date: string | null };
const dates = defineModel<TaskDates>({ required: true });
const fields = [
    { name: 'start_date', label: 'Start date', marker: 'start-marker' },
    { name: 'due_date', label: 'Due date', marker: 'due-marker' },
] as const;

const id = useId();
const panelId = id + '-calendar';
const input = ref<HTMLDivElement | null>(null);
const restoreInputFocus = ref(false);
const startDescriptionId = id + '-start-date';
const dueDescriptionId = id + '-due-date';
const open = ref(false);
const activeField = ref<keyof TaskDates>('start_date');
const visibleDate = shallowRef<DateValue>(parseDate(getLocalizedDayJs().format('YYYY-MM-DD')));
const draft = ref<TaskDates>({ start_date: null, due_date: null });
const hoveredDay = ref<string | null>(null);
const { t } = useInterfaceLanguage();
const organization = inject<ComputedRef<Organization>>('organization');
const weekStartsOn = computed(() => firstDayIndex.value as WeekStartDay);
const format = (date: string) => formatDate(date, organization?.value.date_format);

const displayed = computed(() => (open.value ? draft.value : dates.value));

function endpointLabel(field: keyof TaskDates) {
    const date = displayed.value[field];
    if (date) return format(date);
    if (field === 'due_date' && displayed.value.start_date) return t('Ongoing');
    return t(field === 'start_date' ? 'Start date' : 'Due date');
}

const error = computed(() => {
    const { start_date: start, due_date: due } = draft.value;
    if (!due) return null;
    if (!start) return 'Choose a start date before saving a due date.';
    if (due < start) return 'Due date must be on or after the start date.';
    return null;
});

const visibleRange = computed(() => {
    const { start_date: start, due_date: due } = draft.value;
    const hovered = hoveredDay.value;
    const isStart = activeField.value === 'start_date';
    const previewStart = isStart ? hovered : start;
    const previewDue = isStart ? due : hovered;
    if (
        previewStart &&
        previewDue &&
        previewStart < previewDue &&
        hovered !== draft.value[activeField.value]
    ) {
        return { start: previewStart, due: previewDue, preview: true };
    }
    return { start, due, preview: false };
});

function rangePart(date: string) {
    const { start, due } = visibleRange.value;
    if (!start || !due || start >= due || date < start || date > due) return undefined;
    if (date === start) return 'start';
    return date === due ? 'end' : 'between';
}

function previewDay(value: DateValue, event: PointerEvent) {
    if (event.pointerType !== 'touch') hoveredDay.value = value.toString();
}

function endpointDescription(date: string) {
    return (
        [
            date === draft.value.start_date ? startDescriptionId : null,
            date === draft.value.due_date ? dueDescriptionId : null,
        ]
            .filter(Boolean)
            .join(' ') || undefined
    );
}

function editField(field: keyof TaskDates) {
    hoveredDay.value = null;
    if (!open.value) {
        draft.value = { ...dates.value };
        visibleDate.value = parseDate(
            draft.value[field] ??
                draft.value.start_date ??
                draft.value.due_date ??
                getLocalizedDayJs().format('YYYY-MM-DD')
        );
    } else {
        const date = draft.value[field];
        if (date) visibleDate.value = parseDate(date);
    }
    activeField.value = field;
    open.value = true;
}

function keepOpenOnInput(event: Event) {
    if (event.target instanceof Node && input.value?.contains(event.target)) {
        event.preventDefault();
    }
}

function restoreDateFieldFocus(event: Event) {
    if (!restoreInputFocus.value) return;
    restoreInputFocus.value = false;
    event.preventDefault();
    input.value
        ?.querySelector<HTMLButtonElement>('[data-date-field="' + activeField.value + '"]')
        ?.focus();
}

function selectDate(value: DateValue | undefined) {
    if (!value) return;
    hoveredDay.value = null;
    draft.value[activeField.value] = value.toString();
    visibleDate.value = value;
    if (activeField.value === 'start_date') activeField.value = 'due_date';
}

function clearDraft() {
    hoveredDay.value = null;
    draft.value = { start_date: null, due_date: null };
    activeField.value = 'start_date';
}

function setOngoing() {
    hoveredDay.value = null;
    draft.value.due_date = null;
    activeField.value = 'due_date';
}

function apply() {
    if (error.value) return;
    restoreInputFocus.value = true;
    dates.value = { ...draft.value };
    open.value = false;
}
</script>

<template>
    <Field>
        <FieldLabel :id="id + '-label'" :for="id + '-start_date'">{{ t('Task dates') }}</FieldLabel>
        <Popover v-model:open="open">
            <PopoverAnchor as-child>
                <div
                    ref="input"
                    role="group"
                    :aria-labelledby="id + '-label'"
                    class="date-input flex min-h-9 min-w-0 items-center rounded-md border border-input-border bg-input-background px-2 shadow-sm">
                    <CalendarIcon
                        aria-hidden="true"
                        class="mr-1 size-4 shrink-0 text-icon-default" />
                    <template v-for="field in fields" :key="field.name">
                        <ArrowRight
                            v-if="field.name === 'due_date'"
                            aria-hidden="true"
                            class="mx-1 size-3 shrink-0 text-icon-default" />
                        <button
                            :id="id + '-' + field.name"
                            type="button"
                            :data-date-field="field.name"
                            :data-active="open && activeField === field.name ? '' : undefined"
                            :aria-expanded="open && activeField === field.name"
                            :aria-controls="open ? panelId : undefined"
                            :aria-label="t(field.label) + ' · ' + endpointLabel(field.name)"
                            aria-haspopup="dialog"
                            class="date-input-segment flex min-w-0 flex-1 items-center gap-1.5 rounded px-1.5 py-1 text-left text-sm tabular-nums text-text-primary hover:bg-tertiary focus-visible:outline-2 focus-visible:outline-ring"
                            :class="field.marker"
                            @click="editField(field.name)">
                            <span aria-hidden="true" class="endpoint-key" />
                            <span
                                class="truncate"
                                :class="{ 'text-text-secondary': !displayed[field.name] }">
                                {{ endpointLabel(field.name) }}
                            </span>
                        </button>
                    </template>
                </div>
            </PopoverAnchor>
            <PopoverContent
                :id="panelId"
                :aria-label="t('Task dates')"
                align="start"
                class="w-72 max-w-[calc(100vw-2rem)] p-0"
                @interact-outside="keepOpenOnInput"
                @escape-key-down="restoreInputFocus = true"
                @close-auto-focus="restoreDateFieldFocus">
                <div class="task-date-picker">
                    <span :id="startDescriptionId" class="sr-only">{{ t('Start date') }}</span>
                    <span :id="dueDescriptionId" class="sr-only">{{ t('Due date') }}</span>
                    <Calendar
                        v-model:placeholder="visibleDate"
                        :model-value="null"
                        class="w-fit max-w-full mx-auto"
                        :data-preview-range="visibleRange.preview ? '' : undefined"
                        :week-starts-on="weekStartsOn"
                        @update:model-value="selectDate">
                        <template #day="{ date, month }">
                            <CalendarCellTrigger
                                :day="date"
                                :month="month"
                                class="task-date-day"
                                :data-range="rangePart(date.toString())"
                                :data-preview-endpoint="
                                    visibleRange.preview && hoveredDay === date.toString()
                                        ? activeField
                                        : undefined
                                "
                                @pointerenter="previewDay(date, $event)"
                                @pointerleave="hoveredDay = null"
                                :data-task-start="
                                    date.toString() === draft.start_date ? '' : undefined
                                "
                                :data-task-due="date.toString() === draft.due_date ? '' : undefined"
                                :aria-describedby="endpointDescription(date.toString())">
                                <span class="task-date-number">{{ date.day }}</span>
                            </CalendarCellTrigger>
                        </template>
                    </Calendar>
                    <p
                        v-if="error"
                        role="alert"
                        class="px-3 pb-2 text-xs text-red-600 dark:text-red-400">
                        {{ t(error) }}
                    </p>
                    <div class="flex items-center justify-between gap-1 border-t border-border p-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            :disabled="!draft.start_date && !draft.due_date"
                            @click="clearDraft">
                            {{ t('Clear dates') }}
                        </Button>
                        <Button
                            v-if="draft.start_date && draft.due_date"
                            type="button"
                            variant="ghost"
                            size="sm"
                            :aria-label="t('Ongoing · No due date')"
                            @click="setOngoing">
                            {{ t('Ongoing') }}
                        </Button>
                        <Button type="button" size="sm" :disabled="!!error" @click="apply">
                            {{ t('Apply') }}
                        </Button>
                    </div>
                </div>
            </PopoverContent>
        </Popover>
    </Field>
</template>

<style scoped>
.date-input,
.task-date-picker {
    --task-start-color: #1477a4;
    --task-due-color: #835ba9;
}
.endpoint-key {
    width: 0.55rem;
    height: 0.55rem;
    flex: none;
    border: 1.5px solid var(--endpoint-color);
    border-radius: 2px;
}
.start-marker {
    --endpoint-color: var(--task-start-color);
}
.due-marker {
    --endpoint-color: var(--task-due-color);
}
.date-input-segment[data-active] {
    background: color-mix(in oklch, var(--endpoint-color) 9%, transparent);
    box-shadow: inset 0 -1.5px var(--endpoint-color);
}
.task-date-picker {
    --range-fill: color-mix(in oklch, var(--task-start-color) 15%, transparent);
}
.task-date-picker :deep([data-preview-range]) {
    --range-fill: color-mix(in oklch, var(--task-start-color) 10%, transparent);
}
.task-date-picker :deep(.task-date-day) {
    position: relative;
}
.task-date-picker :deep(.task-date-day[data-range]) {
    isolation: isolate;
    opacity: 1;
}
.task-date-picker :deep(.task-date-day[data-range])::before {
    content: '';
    position: absolute;
    inset-block: 3px;
    inset-inline: var(--range-inset-start, 0) var(--range-inset-end, 0);
    z-index: -1;
    background: var(--range-fill);
    pointer-events: none;
}
.task-date-picker :deep(.task-date-day[data-range='start']) {
    --range-inset-start: 50%;
}
.task-date-picker :deep(.task-date-day[data-range='end']) {
    --range-inset-end: 50%;
}
.task-date-picker :deep(.task-date-day:is([data-range], [data-task-start], [data-task-due])) {
    border: 0;
    background: transparent;
}
.task-date-picker
    :deep(.task-date-day:is([data-task-start], [data-task-due], [data-preview-endpoint])) {
    opacity: 1;
}
.task-date-number {
    display: grid;
    width: 1.75rem;
    height: 1.625rem;
    place-items: center;
    border-radius: 0.375rem;
    line-height: 1;
}
.task-date-picker :deep([data-preview-endpoint='start_date']) {
    --end-color: var(--task-start-color);
}
.task-date-picker :deep([data-preview-endpoint='due_date']) {
    --end-color: var(--task-due-color);
}
.task-date-picker :deep(.task-date-day[data-preview-endpoint]) .task-date-number {
    box-shadow: inset 0 0 0 1px var(--end-color);
    background: color-mix(in oklch, var(--end-color) 12%, var(--theme-color-default-background));
}
.task-date-picker :deep(.task-date-day[data-task-start]) .task-date-number {
    background: var(--task-start-color);
    color: white;
}
.task-date-picker :deep(.task-date-day[data-task-due]) .task-date-number {
    background: var(--task-due-color);
    color: white;
}
.task-date-picker :deep(.task-date-day[data-task-start][data-task-due]) .task-date-number {
    background: linear-gradient(120deg, var(--task-start-color) 35%, var(--task-due-color) 65%);
}
</style>
