<script setup lang="ts">
import { computed, inject, ref, shallowRef, useId, type ComputedRef } from 'vue';
import { parseDate } from '@internationalized/date';
import { Button } from '@/packages/ui/src/Buttons';
import { Field, FieldLabel } from '@/packages/ui/src/field';
import { CalendarIcon, ChevronDown } from '@lucide/vue';
import { CalendarDaysIcon } from '@heroicons/vue/20/solid';
import { Popover, PopoverContent, PopoverTrigger } from '@/packages/ui/src/popover';
import { RangeCalendar } from '@/packages/ui/src/range-calendar';
import { firstDayIndex, formatDate, type WeekStartDay } from '@/packages/ui/src/utils/time';
import type { Organization } from '@/packages/api/src';
import type { DateRange } from 'reka-ui';

const dates = defineModel<{ start_date: string | null; due_date: string | null }>({
    required: true,
});
const triggerId = useId();
const draftDates = shallowRef<DateRange>({ start: undefined, end: undefined });
const datePickerOpen = ref(false);
const organization = inject<ComputedRef<Organization>>('organization');
const weekStartsOn = computed(() => firstDayIndex.value as WeekStartDay);
const dateRangeLabel = computed(() => {
    const { start_date: start, due_date: end } = dates.value;
    if (!start || !end) return 'Start date → Due date';
    const dateFormat = organization?.value.date_format;
    return formatDate(start, dateFormat) + ' → ' + formatDate(end, dateFormat);
});

function updateDatePickerOpen(open: boolean) {
    if (open) {
        const { start_date: start, due_date: end } = dates.value;
        draftDates.value = {
            start: start ? parseDate(start) : undefined,
            end: end ? parseDate(end) : undefined,
        };
    }
    datePickerOpen.value = open;
}

function applyTaskDates() {
    const { start, end } = draftDates.value;
    if (!start || !end) return;
    dates.value = { start_date: start.toString(), due_date: end.toString() };
    datePickerOpen.value = false;
}

function clearTaskDates() {
    dates.value = { start_date: null, due_date: null };
    datePickerOpen.value = false;
}
</script>

<template>
    <Field>
        <FieldLabel :for="triggerId" :icon="CalendarDaysIcon">Task dates</FieldLabel>
        <Popover :open="datePickerOpen" @update:open="updateDatePickerOpen">
            <PopoverTrigger as-child>
                <Button
                    :id="triggerId"
                    type="button"
                    variant="input"
                    class="w-full justify-between">
                    <span class="flex min-w-0 items-center gap-2">
                        <CalendarIcon class="size-4 shrink-0 text-icon-default" />
                        <span
                            class="truncate tabular-nums"
                            :class="{ 'text-text-tertiary': !dates.due_date }">
                            {{ dateRangeLabel }}
                        </span>
                    </span>
                    <ChevronDown class="size-4 shrink-0 text-icon-default" />
                </Button>
            </PopoverTrigger>
            <PopoverContent align="start" class="w-auto p-0">
                <div class="border-b border-border px-3 py-2.5">
                    <p class="text-sm font-medium">Start date → Due date</p>
                    <p class="mt-0.5 text-xs text-text-tertiary">
                        Select a start date, then a due date.
                    </p>
                </div>
                <RangeCalendar v-model="draftDates" initial-focus :week-starts-on="weekStartsOn" />
                <div class="flex items-center justify-between border-t border-border px-3 py-2.5">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        :disabled="!dates.due_date && !draftDates.start"
                        @click="clearTaskDates">
                        Clear
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        :disabled="!draftDates.start || !draftDates.end"
                        @click="applyTaskDates">
                        Apply
                    </Button>
                </div>
            </PopoverContent>
        </Popover>
    </Field>
</template>
