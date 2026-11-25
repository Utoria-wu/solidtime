<script setup lang="ts">
import { computed, inject, ref, shallowRef, useId, type ComputedRef } from 'vue';
import { parseDate } from '@internationalized/date';
import { Button } from '@/packages/ui/src/Buttons';
import { Field, FieldLabel } from '@/packages/ui/src/field';
import { CalendarIcon, ChevronDown } from '@lucide/vue';
import { CalendarDaysIcon } from '@heroicons/vue/20/solid';
import { Popover, PopoverContent, PopoverTrigger } from '@/packages/ui/src/popover';
import { Calendar } from '@/packages/ui/src/calendar';
import { useInterfaceLanguage } from '@/utils/useInterfaceLanguage';
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
const ongoing = ref(false);
const ongoingStart = shallowRef<DateRange['start']>();
const { t } = useInterfaceLanguage();
const organization = inject<ComputedRef<Organization>>('organization');
const weekStartsOn = computed(() => firstDayIndex.value as WeekStartDay);
const dateRangeLabel = computed(() => {
    const { start_date: start, due_date: end } = dates.value;
    const dateFormat = organization?.value.date_format;
    if (start && !end) return formatDate(start, dateFormat) + ' → ' + t('Ongoing');
    if (!start || !end) return t('No dates set');
    if (start === end) return formatDate(start, dateFormat);
    return formatDate(start, dateFormat) + ' → ' + formatDate(end, dateFormat);
});

function updateDatePickerOpen(open: boolean) {
    if (open) {
        const { start_date: start, due_date: end } = dates.value;
        ongoing.value = Boolean(start && !end);
        ongoingStart.value = start ? parseDate(start) : undefined;
        draftDates.value = {
            start: start ? parseDate(start) : undefined,
            end: end ? parseDate(end) : undefined,
        };
    }
    datePickerOpen.value = open;
}

function setOngoing(next: boolean) {
    if (next === ongoing.value) return;
    if (ongoing.value) {
        draftDates.value = {
            start: ongoingStart.value,
            end:
                draftDates.value.start?.toString() === ongoingStart.value?.toString()
                    ? draftDates.value.end
                    : undefined,
        };
    } else {
        ongoingStart.value = draftDates.value.start;
    }
    ongoing.value = next;
}

const canApply = computed(() =>
    ongoing.value
        ? Boolean(ongoingStart.value)
        : Boolean(draftDates.value.start && draftDates.value.end)
);

function applyTaskDates() {
    if (!canApply.value) return;
    dates.value = ongoing.value
        ? { start_date: ongoingStart.value!.toString(), due_date: null }
        : {
              start_date: draftDates.value.start!.toString(),
              due_date: draftDates.value.end!.toString(),
          };
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
                            :class="{ 'text-text-tertiary': !dates.start_date }">
                            {{ dateRangeLabel }}
                        </span>
                    </span>
                    <ChevronDown class="size-4 shrink-0 text-icon-default" />
                </Button>
            </PopoverTrigger>
            <PopoverContent align="start" class="w-auto p-0">
                <div class="border-b border-border px-3 py-2.5">
                    <div
                        role="group"
                        :aria-label="t('Task dates')"
                        class="grid grid-cols-2 gap-1 rounded-lg bg-tertiary p-1">
                        <button
                            v-for="mode in [false, true]"
                            :key="String(mode)"
                            type="button"
                            :aria-pressed="ongoing === mode"
                            class="rounded-md px-2 py-1.5 text-xs font-medium text-text-secondary hover:text-text-primary focus-visible:outline focus-visible:outline-1 focus-visible:outline-offset-2 focus-visible:outline-text-secondary aria-pressed:bg-default-background aria-pressed:text-text-primary aria-pressed:shadow-sm"
                            @click="setOngoing(mode)">
                            {{ t(mode ? 'Ongoing' : 'Date range') }}
                        </button>
                    </div>
                    <p v-if="ongoing" class="mt-2 text-xs text-text-secondary">
                        {{ t('Select a start date. No due date is needed.') }}
                    </p>
                </div>
                <Calendar
                    v-if="ongoing"
                    v-model="ongoingStart"
                    prevent-deselect
                    initial-focus
                    :week-starts-on="weekStartsOn" />
                <RangeCalendar
                    v-else
                    v-model="draftDates"
                    class="task-date-calendar"
                    initial-focus
                    :week-starts-on="weekStartsOn" />
                <div class="flex items-center justify-between border-t border-border px-3 py-2.5">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        :disabled="!dates.start_date && !draftDates.start && !ongoingStart"
                        @click="clearTaskDates">
                        Clear
                    </Button>
                    <Button type="button" size="sm" :disabled="!canApply" @click="applyTaskDates">
                        Apply
                    </Button>
                </div>
            </PopoverContent>
        </Popover>
    </Field>
</template>

<style scoped>
.task-date-calendar :deep([data-selection-start]),
.task-date-calendar :deep([data-selection-end]) {
    background-image: linear-gradient(var(--endpoint-color), var(--endpoint-color));
    background-position: bottom;
    background-size: 100% 3px;
    background-repeat: no-repeat;
}
.task-date-calendar :deep([data-selection-start]) {
    --endpoint-color: #0ea5e9;
}
.task-date-calendar :deep([data-selection-end]) {
    --endpoint-color: #f59e0b;
}
.task-date-calendar :deep([data-selection-start][data-selection-end]) {
    background-image: linear-gradient(90deg, #0ea5e9 50%, #f59e0b 50%);
}
</style>
