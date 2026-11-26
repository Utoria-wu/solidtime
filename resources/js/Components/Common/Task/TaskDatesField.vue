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
    draft.value[activeField.value] = value.toString();
    visibleDate.value = value;
    if (activeField.value === 'start_date') activeField.value = 'due_date';
}

function clearDraft() {
    draft.value = { start_date: null, due_date: null };
    activeField.value = 'start_date';
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
                        :week-starts-on="weekStartsOn"
                        @update:model-value="selectDate">
                        <template #day="{ date, month }">
                            <CalendarCellTrigger
                                :day="date"
                                :month="month"
                                class="task-date-day"
                                :data-task-start="
                                    date.toString() === draft.start_date ? '' : undefined
                                "
                                :data-task-due="date.toString() === draft.due_date ? '' : undefined"
                                :aria-describedby="endpointDescription(date.toString())" />
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
                            @click="
                                draft.due_date = null;
                                activeField = 'due_date';
                            ">
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
    --task-start-color: #1681b2;
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
.task-date-picker :deep(.task-date-day:is([data-task-start], [data-task-due])) {
    position: relative;
    color: var(--theme-color-text-primary);
    opacity: 1;
}
.task-date-picker :deep(.task-date-day:is([data-task-start], [data-task-due]))::after {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    border-radius: inherit;
}
.task-date-picker :deep(.task-date-day[data-task-start])::after {
    box-shadow: inset 0 0 0 1.5px var(--task-start-color);
}
.task-date-picker :deep(.task-date-day[data-task-due])::after {
    box-shadow: inset 0 0 0 1.5px var(--task-due-color);
}
.task-date-picker :deep(.task-date-day[data-task-start][data-task-due])::after {
    box-shadow:
        inset 0 0 0 1.5px var(--task-start-color),
        inset 0 0 0 3px var(--task-due-color);
}
</style>
