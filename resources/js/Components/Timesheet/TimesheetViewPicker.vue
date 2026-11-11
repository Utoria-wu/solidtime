<script setup lang="ts">
import { Button } from '@/packages/ui/src/Buttons';

export type TimesheetView = 'days' | 'weeks' | 'months' | 'year';

const view = defineModel<TimesheetView>({ required: true });
const views = [
    ['days', 'Days'],
    ['weeks', 'Weeks'],
    ['months', 'Months'],
    ['year', 'Year'],
] as const;
</script>

<template>
    <div
        role="group"
        aria-label="Timesheet view"
        class="inline-flex rounded-md border border-default-background-separator bg-background p-0.5">
        <Button
            v-for="[option, label] in views"
            :key="option"
            type="button"
            size="sm"
            variant="ghost"
            :class="{ 'bg-default-background shadow-sm font-semibold': view === option }"
            :aria-pressed="view === option"
            @click="view = option"
            >{{ label }}</Button
        >
    </div>
</template>
