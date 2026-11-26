<script setup lang="ts">
import { Tabs, TabsList } from '../tabs';
import TabBarItem from '../TabBar/TabBarItem.vue';
import DateRangeNavigator from '../Input/DateRangeNavigator.vue';
import CalendarSettingsPopover from './CalendarSettingsPopover.vue';
import type { CalendarSettings } from './calendarSettings';
import type { CalendarView } from './useCalendarNavigation';
import { Button } from '../Buttons';
import { ChevronLeft, ChevronRight } from '@lucide/vue';

const tabClass = 'border-transparent py-1 text-xs focus-visible:ring-2 focus-visible:ring-ring';

defineProps<{
    rangeLabel: string;
    activeView: CalendarView;
    settings: CalendarSettings;
}>();

const emit = defineEmits<{
    prev: [];
    next: [];
    today: [];
    'change-view': [view: CalendarView];
    'update:settings': [value: CalendarSettings];
}>();
</script>

<template>
    <div
        class="flex shrink-0 flex-wrap items-center justify-between gap-x-4 gap-y-2 bg-default-background px-3 py-3 sm:px-6">
        <div
            v-if="activeView === 'month' || activeView === 'year'"
            class="flex flex-wrap items-center gap-1.5">
            <Button
                variant="ghost"
                size="icon"
                class="size-8 rounded-full hover:bg-secondary"
                aria-label="Previous"
                @click="emit('prev')">
                <ChevronLeft class="size-4" />
            </Button>
            <h1
                class="order-first mr-2 whitespace-nowrap text-xl font-semibold tracking-tight sm:text-2xl"
                data-testid="calendar-title">
                {{ rangeLabel }}
            </h1>
            <Button
                variant="ghost"
                size="icon"
                class="size-8 rounded-full hover:bg-secondary"
                aria-label="Next"
                @click="emit('next')">
                <ChevronRight class="size-4" />
            </Button>
            <Button variant="outline" size="sm" class="ml-1" @click="emit('today')">Today</Button>
        </div>
        <DateRangeNavigator
            v-else
            :label="rangeLabel"
            trigger-test-id="calendar-title"
            trigger-aria-label="today"
            @previous="emit('prev')"
            @next="emit('next')"
            @select="emit('today')" />

        <div class="flex min-w-0 flex-wrap items-center gap-2">
            <Tabs
                :model-value="activeView"
                @update:model-value="(v) => emit('change-view', v as CalendarView)">
                <TabsList class="gap-0.5 rounded-md bg-tertiary p-0.5" aria-label="Calendar view">
                    <TabBarItem :class="tabClass" value="timeGridDay">Day</TabBarItem>
                    <TabBarItem :class="tabClass" value="timeGridWeek">Week</TabBarItem>
                    <TabBarItem :class="tabClass" value="month">Month</TabBarItem>
                    <TabBarItem :class="tabClass" value="year">Year</TabBarItem>
                </TabsList>
            </Tabs>
            <CalendarSettingsPopover
                v-if="activeView === 'timeGridDay' || activeView === 'timeGridWeek'"
                :settings="settings"
                @update:settings="(v) => emit('update:settings', v)" />
            <slot />
        </div>
    </div>
</template>
