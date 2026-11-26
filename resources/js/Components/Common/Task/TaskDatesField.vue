<script lang="ts">
export type TaskDates = { start_date: string | null; due_date: string | null };

export function getTaskDateError({ start_date: start, due_date: due }: TaskDates) {
    if (!due) return null;
    if (!start) return 'Choose a start date before saving a due date.';
    if (due < start) return 'Due date must be on or after the start date.';
    return null;
}
</script>

<script setup lang="ts">
import { computed, inject, ref, useId, type ComputedRef } from 'vue';
import { parseDate, type DateValue } from '@internationalized/date';
import { Button } from '@/packages/ui/src/Buttons';
import { Field, FieldLabel } from '@/packages/ui/src/field';
import { CalendarIcon, ChevronDown } from '@lucide/vue';
import { Popover, PopoverContent, PopoverTrigger } from '@/packages/ui/src/popover';
import { Calendar } from '@/packages/ui/src/calendar';
import { useInterfaceLanguage } from '@/utils/useInterfaceLanguage';
import { firstDayIndex, formatDate, type WeekStartDay } from '@/packages/ui/src/utils/time';
import type { Organization } from '@/packages/api/src';

type DateField = keyof TaskDates;

const dates = defineModel<TaskDates>({ required: true });
const fields = [
    { name: 'start_date', label: 'Start date' },
    { name: 'due_date', label: 'Due date' },
] as const;
const id = useId();
const errorId = id + '-error';
const openField = ref<DateField | null>(null);
const { t } = useInterfaceLanguage();
const organization = inject<ComputedRef<Organization>>('organization');
const weekStartsOn = computed(() => firstDayIndex.value as WeekStartDay);
const error = computed(() => getTaskDateError(dates.value));
const calendarDates = computed(() => {
    const { start_date: start, due_date: due } = dates.value;
    return {
        start_date: start ? parseDate(start) : undefined,
        due_date: due ? parseDate(due) : undefined,
    };
});

function setPickerOpen(field: DateField, open: boolean) {
    if (open) openField.value = field;
    else if (openField.value === field) openField.value = null;
}

function selectDate(field: DateField, value: DateValue | undefined) {
    if (value) dates.value = { ...dates.value, [field]: value.toString() };
    openField.value = null;
}

function clearDates() {
    dates.value = { start_date: null, due_date: null };
    openField.value = null;
}

function setOngoing() {
    dates.value = { ...dates.value, due_date: null };
    openField.value = null;
}
</script>

<template>
    <div class="grid min-w-0 grid-cols-2 gap-x-4 gap-y-2">
        <Field v-for="field in fields" :key="field.name" class="min-w-0">
            <FieldLabel :for="id + field.name">{{ t(field.label) }}</FieldLabel>
            <Popover
                :open="openField === field.name"
                @update:open="setPickerOpen(field.name, $event)">
                <PopoverTrigger as-child>
                    <Button
                        :id="id + field.name"
                        type="button"
                        variant="input"
                        class="w-full justify-between gap-1"
                        :aria-invalid="
                            Boolean(error) && field.name === 'due_date' ? true : undefined
                        "
                        :aria-describedby="
                            error && field.name === 'due_date' ? errorId : undefined
                        ">
                        <span class="flex min-w-0 items-center gap-1.5">
                            <CalendarIcon class="size-4 shrink-0 text-icon-default" />
                            <span
                                class="truncate tabular-nums"
                                :class="{ 'text-text-tertiary': !dates[field.name] }">
                                {{
                                    dates[field.name]
                                        ? formatDate(dates[field.name]!, organization?.date_format)
                                        : field.name === 'due_date' && dates.start_date
                                          ? t('Ongoing')
                                          : t('Pick a date')
                                }}
                            </span>
                        </span>
                        <ChevronDown class="size-4 shrink-0 text-icon-default" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent align="start" class="w-auto p-0">
                    <Calendar
                        initial-focus
                        :model-value="calendarDates[field.name]"
                        :week-starts-on="weekStartsOn"
                        @update:model-value="selectDate(field.name, $event)" />
                    <div
                        v-if="dates.start_date || dates.due_date"
                        class="border-t border-border p-2">
                        <Button
                            v-if="field.name === 'start_date'"
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="clearDates">
                            {{ t('Clear dates') }}
                        </Button>
                        <Button
                            v-else-if="dates.start_date"
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="setOngoing">
                            {{ t('Ongoing · No due date') }}
                        </Button>
                    </div>
                </PopoverContent>
            </Popover>
        </Field>
        <p
            v-if="error"
            :id="errorId"
            role="alert"
            class="col-span-2 text-xs text-red-600 dark:text-red-400">
            {{ t(error) }}
        </p>
    </div>
</template>
