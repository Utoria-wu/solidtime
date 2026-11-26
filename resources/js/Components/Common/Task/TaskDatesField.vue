<script setup lang="ts">
import { computed, inject, ref, shallowRef, useId, type ComputedRef } from 'vue';
import { parseDate, type DateValue } from '@internationalized/date';
import { Button } from '@/packages/ui/src/Buttons';
import { Field, FieldLabel } from '@/packages/ui/src/field';
import { CalendarIcon, ChevronDown } from '@lucide/vue';
import { Popover, PopoverContent, PopoverTrigger } from '@/packages/ui/src/popover';
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

const summary = computed(() => {
    const { start_date: start, due_date: due } = dates.value;
    if (!start) return due ? format(due) : t('No dates set');
    if (!due) return format(start) + ' · ' + t('Ongoing');
    return start === due ? format(start) : format(start) + ' – ' + format(due);
});

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

function updateOpen(value: boolean) {
    if (value) {
        draft.value = { ...dates.value };
        activeField.value = 'start_date';
        visibleDate.value = parseDate(
            draft.value.start_date ??
                draft.value.due_date ??
                getLocalizedDayJs().format('YYYY-MM-DD')
        );
    }
    open.value = value;
}

function editField(field: keyof TaskDates) {
    activeField.value = field;
    const date = draft.value[field];
    if (date) visibleDate.value = parseDate(date);
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
    dates.value = { ...draft.value };
    open.value = false;
}
</script>

<template>
    <Field>
        <FieldLabel :for="id">{{ t('Task dates') }}</FieldLabel>
        <Popover :open="open" @update:open="updateOpen">
            <PopoverTrigger as-child>
                <Button :id="id" type="button" variant="input" class="w-full justify-between">
                    <span class="flex min-w-0 items-center gap-2">
                        <CalendarIcon class="size-4 shrink-0 text-icon-default" />
                        <span
                            class="truncate tabular-nums"
                            :class="{ 'text-text-tertiary': !dates.start_date && !dates.due_date }">
                            {{ summary }}
                        </span>
                    </span>
                    <ChevronDown class="size-4 shrink-0 text-icon-default" />
                </Button>
            </PopoverTrigger>
            <PopoverContent align="start" class="w-72 max-w-[calc(100vw-2rem)] p-0">
                <div class="task-date-picker">
                    <span :id="startDescriptionId" class="sr-only">{{ t('Start date') }}</span>
                    <span :id="dueDescriptionId" class="sr-only">{{ t('Due date') }}</span>
                    <div
                        role="group"
                        :aria-label="t('Task dates')"
                        class="grid grid-cols-2 gap-2 border-b border-border p-3">
                        <button
                            v-for="field in fields"
                            :key="field.name"
                            type="button"
                            :aria-pressed="activeField === field.name"
                            class="flex min-w-0 flex-col gap-0.5 rounded-md border border-border px-2.5 py-1.5 text-left hover:bg-tertiary focus-visible:outline-2 focus-visible:outline-ring aria-pressed:border-accent-500 aria-pressed:bg-accent-500/5"
                            @click="editField(field.name)">
                            <span
                                class="flex items-center gap-1.5 text-xs font-medium text-text-secondary">
                                <span
                                    aria-hidden="true"
                                    class="endpoint-key"
                                    :class="field.marker" />
                                {{ t(field.label) }}
                            </span>
                            <span
                                class="max-w-full truncate text-sm tabular-nums text-text-primary">
                                {{
                                    draft[field.name]
                                        ? format(draft[field.name]!)
                                        : field.name === 'due_date' && draft.start_date
                                          ? t('Ongoing')
                                          : t('Pick a date')
                                }}
                            </span>
                        </button>
                    </div>
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
.task-date-picker {
    --task-start-color: #1681b2;
    --task-due-color: #835ba9;
}
.endpoint-key {
    width: 0.55rem;
    height: 0.55rem;
    flex: none;
    border: 1.5px solid;
    border-radius: 2px;
}
.start-marker {
    color: var(--task-start-color);
}
.due-marker {
    color: var(--task-due-color);
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
