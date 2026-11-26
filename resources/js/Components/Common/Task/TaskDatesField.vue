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

type TaskDates = { start_date: string | null; due_date: string | null };
const dates = defineModel<TaskDates>({ required: true });
const fields = [
    { name: 'start_date', label: 'Start date' },
    { name: 'due_date', label: 'Due date' },
] as const;

const id = useId();
const open = ref(false);
const activeField = ref<keyof TaskDates>('start_date');
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

const selectedDate = computed(() => {
    const date = draft.value[activeField.value];
    return date ? parseDate(date) : undefined;
});

const calendarAnchor = computed(() => {
    const date = draft.value[activeField.value] ?? draft.value.start_date ?? draft.value.due_date;
    return date ? parseDate(date) : undefined;
});

const error = computed(() => {
    const { start_date: start, due_date: due } = draft.value;
    if (!due) return null;
    if (!start) return 'Choose a start date before saving a due date.';
    if (due < start) return 'Due date must be on or after the start date.';
    return null;
});

function updateOpen(value: boolean) {
    if (value) {
        draft.value = { ...dates.value };
        activeField.value = 'start_date';
    }
    open.value = value;
}

function selectDate(value: DateValue | undefined) {
    if (value) draft.value[activeField.value] = value.toString();
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
                        @click="activeField = field.name">
                        <span class="text-xs font-medium text-text-secondary">{{
                            t(field.label)
                        }}</span>
                        <span class="max-w-full truncate text-sm tabular-nums text-text-primary">
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
                    :key="activeField"
                    class="w-fit max-w-full mx-auto"
                    prevent-deselect
                    :model-value="selectedDate"
                    :default-placeholder="calendarAnchor"
                    :week-starts-on="weekStartsOn"
                    @update:model-value="selectDate" />
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
            </PopoverContent>
        </Popover>
    </Field>
</template>
