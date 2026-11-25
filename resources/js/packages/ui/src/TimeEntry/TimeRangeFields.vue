<script setup lang="ts">
import { watch } from 'vue';
import { Field, FieldLabel } from '../field';
import DatePicker from '@/packages/ui/src/Input/DatePicker.vue';
import DurationHumanInput from '@/packages/ui/src/Input/DurationHumanInput.vue';
import TimePickerSimple from '@/packages/ui/src/Input/TimePickerSimple.vue';
import { getLocalizedDayJs } from '@/packages/ui/src/utils/time';

// Local (user timezone) ISO strings, as produced by getLocalizedDayJs(...).format()
const start = defineModel<string>('start', { required: true });
const end = defineModel<string>('end', { required: true });

defineProps<{
    datePickerSize?: 'sm';
}>();

// Moving the start to or past the end drags the end along, preserving the
// range's previous duration, so the range never collapses or inverts.
watch(start, (value, oldValue) => {
    if (getLocalizedDayJs(end.value).isAfter(getLocalizedDayJs(value))) return;
    const previousDuration = Math.max(
        0,
        getLocalizedDayJs(end.value).diff(getLocalizedDayJs(oldValue), 'second')
    );
    end.value = getLocalizedDayJs(value).add(previousDuration, 'second').format();
});
</script>

<template>
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-4">
        <Field class="col-span-2 sm:col-span-3">
            <FieldLabel>Duration</FieldLabel>
            <DurationHumanInput v-model:start="start" v-model:end="end" name="Duration" />
        </Field>
        <Field>
            <FieldLabel>Start</FieldLabel>
            <div class="flex flex-col gap-2">
                <TimePickerSimple v-model="start" class="w-full"></TimePickerSimple>
                <DatePicker
                    v-model="start"
                    :size="datePickerSize"
                    class="w-full"
                    tabindex="1"></DatePicker>
            </div>
        </Field>
        <Field>
            <FieldLabel>End</FieldLabel>
            <div class="flex flex-col gap-2">
                <TimePickerSimple v-model="end" class="w-full"></TimePickerSimple>
                <DatePicker
                    v-model="end"
                    :size="datePickerSize"
                    class="w-full"
                    tabindex="1"></DatePicker>
            </div>
        </Field>
    </div>
</template>
