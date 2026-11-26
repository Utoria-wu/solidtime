<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTimeEntriesCalendarQuery } from '@/utils/useTimeEntriesCalendarQuery';
import { useTimeEntriesMutations } from '@/utils/useTimeEntriesMutations';
import { computed, ref, onMounted } from 'vue';
import { useEventListener, useLocalStorage } from '@vueuse/core';
import { Bell, ChevronDown, Plus, Upload } from '@lucide/vue';
import axios from 'axios';
import type { Dayjs } from 'dayjs';
import { useQueryClient } from '@tanstack/vue-query';
import {
    type Client,
    type CreateClientBody,
    type CreateProjectBody,
    type Project,
    type TimeEntry,
} from '@/packages/api/src';
import { TimeEntryCalendar } from '@/packages/ui/src';
import { Button } from '@/packages/ui/src/Buttons';
import Checkbox from '@/packages/ui/src/Input/Checkbox.vue';
import { getDayJsInstance } from '@/packages/ui/src/utils/time';
import type { CalendarView } from '@/packages/ui/src/FullCalendar/useCalendarNavigation';
import CalendarOverview, {
    type CalendarOverviewItem,
} from '@/Components/Calendar/CalendarOverview.vue';
import ReminderDialog from '@/Components/Calendar/ReminderDialog.vue';
import {
    personalCalendarChanged,
    personalCalendarError,
    personalCalendarUrl,
    usePersonalCalendar,
    type Reminder,
    type ReminderFields,
} from '@/Components/Calendar/personalCalendar';
import type { ActivityPeriod } from '@/packages/ui/src/FullCalendar/activityTypes';
import { isAllowedToPerformPremiumAction } from '@/utils/billing';
import { useTagsStore } from '@/utils/useTags';
import { useProjectsQuery } from '@/utils/useProjectsQuery';
import { useClientsQuery } from '@/utils/useClientsQuery';
import { useTasksQuery } from '@/utils/useTasksQuery';
import { useTagsQuery } from '@/utils/useTagsQuery';
import { useProjectsStore } from '@/utils/useProjects';
import { useClientsStore } from '@/utils/useClients';
import { getOrganizationCurrencyString } from '@/utils/money';
import { canCreateProjects } from '@/utils/permissions';
import { useCurrentTimeEntryStore } from '@/utils/useCurrentTimeEntry';
import { useOrganizationQuery } from '@/utils/useOrganizationQuery';
import { getCurrentOrganizationId, getCurrentUserId } from '@/utils/useUser';
import { useNotificationsStore } from '@/utils/notification';

const { organization } = useOrganizationQuery(getCurrentOrganizationId()!);
const calendarStart = ref<Dayjs | undefined>(undefined);
const calendarEnd = ref<Dayjs | undefined>(undefined);
const calendarView = ref<CalendarView>('timeGridWeek');
const calendarDateKey = (day: Dayjs) => day.format('YYYY-MM-DD');
const startKey = computed(() => (calendarStart.value ? calendarDateKey(calendarStart.value) : ''));
const endKey = computed(() => (calendarEnd.value ? calendarDateKey(calendarEnd.value) : ''));
const isTimeGrid = computed(
    () => calendarView.value === 'timeGridDay' || calendarView.value === 'timeGridWeek'
);

// Optional deep link (e.g. "Fix in calendar") that opens the calendar on a specific day
const calendarLink = new URLSearchParams(window.location.search);
const initialDate = calendarLink.get('date');
const initialEntryId = calendarLink.get('entry');
const viewParam = calendarLink.get('view');
const initialView: CalendarView = initialEntryId
    ? 'timeGridDay'
    : viewParam === 'month' || viewParam === 'year'
      ? viewParam
      : 'timeGridWeek';

// Test-injectable activity periods (for E2E testing).
// These hooks are no-ops in production — they only take effect when test code
// explicitly sets window globals, so they are safe to ship.
const testActivityPeriods = ref<ActivityPeriod[]>([]);

onMounted(() => {
    (window as unknown as Record<string, unknown>).__TEST_SET_ACTIVITY_PERIODS__ = (
        data: ActivityPeriod[]
    ) => {
        testActivityPeriods.value = data;
    };

    const windowData = (window as unknown as Record<string, unknown>).__TEST_ACTIVITY_PERIODS__;
    if (Array.isArray(windowData)) {
        setTimeout(() => {
            testActivityPeriods.value = windowData;
        }, 2000);
    }
});

const {
    data: timeEntryResponse,
    isLoading: timeEntriesLoading,
    isError: timeEntriesError,
} = useTimeEntriesCalendarQuery(calendarStart, calendarEnd, isTimeGrid);

const currentTimeEntries = computed(() => {
    return timeEntryResponse?.value?.data || [];
});
const undoAction = ref<{ label: string; run: () => Promise<unknown> }>();
const undoing = ref(false);
const notifications = useNotificationsStore();
const queryClient = useQueryClient();
const {
    data: personalCalendar,
    isLoading: personalLoading,
    isError: personalError,
} = usePersonalCalendar(startKey, endKey);
const sources = useLocalStorage(
    `solidtime:calendar-sources:${getCurrentUserId()}`,
    {
        entries: true,
        reminders: true,
        holidays: true,
    },
    { mergeDefaults: true }
);
const sourceCheckboxClass =
    'focus:ring-0 focus:ring-offset-0 focus-visible:ring-1 focus-visible:ring-ring';
const dayjs = getDayJsInstance();
const calendarItems = computed(() => {
    const days = new Map<string, CalendarOverviewItem[]>();
    if (!startKey.value || !endKey.value) return days;

    function add(item: CalendarOverviewItem) {
        let day = dayjs.utc(item.start < startKey.value ? startKey.value : item.start);
        const limit = item.end < endKey.value ? item.end : endKey.value;
        for (let key = calendarDateKey(day); key < limit; key = calendarDateKey(day)) {
            const records = days.get(key);
            if (records) records.push(item);
            else days.set(key, [item]);
            day = day.add(1, 'day');
        }
    }

    if (sources.value.holidays) {
        for (const holiday of personalCalendar.value?.holidays ?? []) {
            add({
                key: `holiday-${holiday.id}`,
                kind: holiday.workday ? 'workday' : 'holiday',
                title: holiday.title,
                start: holiday.start_date,
                end: holiday.end_date,
            });
        }
    }
    if (sources.value.reminders) {
        for (const reminder of personalCalendar.value?.reminders ?? []) {
            add({
                key: reminder.id,
                kind: 'reminder',
                title: reminder.title,
                reminder,
                time: reminder.time,
                start: reminder.date,
                end: calendarDateKey(dayjs.utc(reminder.date).add(1, 'day')),
            });
        }
    }
    return days;
});

const reminderOpen = ref(false);
const editingReminder = ref<Reminder | null>(null);
const reminderDate = ref('');
const reminderBusy = ref(false);
const reminderError = ref('');
const sourceMenu = ref<HTMLDetailsElement | null>(null);
const importInput = ref<HTMLInputElement | null>(null);
const importing = ref(false);

function openReminder(date: Dayjs, reminder: Reminder | null = null) {
    editingReminder.value = reminder;
    reminderDate.value = calendarDateKey(date);
    reminderError.value = '';
    reminderOpen.value = true;
}

function refreshPersonalCalendar() {
    window.dispatchEvent(new Event(personalCalendarChanged));
    return queryClient.invalidateQueries({ queryKey: ['personalCalendar', getCurrentUserId()] });
}

async function changeReminder(fields?: ReminderFields) {
    if (reminderBusy.value) return;
    reminderBusy.value = true;
    reminderError.value = '';
    const id = editingReminder.value?.id;
    const url = personalCalendarUrl + '/reminders' + (id ? `/${id}` : '');
    try {
        if (fields && id) await axios.put(url, fields);
        else if (fields) await axios.post(url, fields);
        else if (id) await axios.delete(url);
        reminderOpen.value = false;
        if (fields) sources.value.reminders = true;
        await refreshPersonalCalendar();
        notifications.addNotification('success', fields ? 'Reminder saved' : 'Reminder deleted');
    } catch (error) {
        reminderError.value = personalCalendarError(error);
    } finally {
        reminderBusy.value = false;
    }
}

async function importHolidays(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file || importing.value) return;
    importing.value = true;
    try {
        const upload = new FormData();
        upload.append('calendar', file);
        const { data } = await axios.post<{ imported: number; skipped: number }>(
            personalCalendarUrl + '/holidays/import',
            upload
        );
        sources.value.holidays = true;
        if (sourceMenu.value) sourceMenu.value.open = false;
        await refreshPersonalCalendar();
        notifications.addNotification(
            'success',
            `Imported ${data.imported} holiday entries`,
            data.skipped ? `Skipped ${data.skipped} unsupported entries.` : undefined
        );
    } catch (error) {
        notifications.addNotification('error', personalCalendarError(error));
    } finally {
        importing.value = false;
    }
}

function entryUrl(id: string) {
    return `/api/v1/organizations/${getCurrentOrganizationId()}/time-entries/${id}`;
}

function refreshEntryTotals() {
    for (const name of ['timeEntries', 'tasks', 'projects', 'goals']) {
        void queryClient.invalidateQueries({ queryKey: [name] });
    }
}

async function undoLastChange() {
    const action = undoAction.value;
    if (!action || undoing.value) return;
    undoing.value = true;
    try {
        await action.run();
        if (undoAction.value === action) undoAction.value = undefined;
        refreshEntryTotals();
    } catch {
        // The mutation shows the server error.
    } finally {
        undoing.value = false;
    }
}

useEventListener('keydown', (event: KeyboardEvent) => {
    if (
        !undoAction.value ||
        undoing.value ||
        event.defaultPrevented ||
        event.isComposing ||
        event.repeat ||
        !(event.ctrlKey || event.metaKey) ||
        event.altKey ||
        event.shiftKey ||
        event.key.toLowerCase() !== 'z'
    )
        return;
    if (
        event.target instanceof HTMLElement &&
        (event.target.isContentEditable ||
            event.target.closest('input, textarea, select, [role="textbox"], [role="dialog"]'))
    )
        return;
    event.preventDefault();
    void undoLastChange();
});

const {
    createTimeEntry: createTimeEntryMutation,
    updateTimeEntry: updateTimeEntryMutation,
    deleteTimeEntry: deleteTimeEntryMutation,
} = useTimeEntriesMutations();

// Wrap mutations to match expected Promise<void> return type
async function createTimeEntry(
    entry: Omit<import('@/packages/api/src').TimeEntry, 'id' | 'organization_id' | 'user_id'>
): Promise<void> {
    await createTimeEntryMutation(entry);
    undoAction.value = undefined;
}

async function updateTimeEntry(entry: TimeEntry): Promise<void> {
    const previous = currentTimeEntries.value.find((item) => item.id === entry.id);
    await updateTimeEntryMutation(entry);
    if (previous?.end) {
        const snapshot = { ...previous, tags: [...previous.tags] };
        undoAction.value = {
            label: 'Time entry updated',
            run: () => updateTimeEntryMutation(snapshot),
        };
    }
}

async function deleteTimeEntry(timeEntryId: string): Promise<void> {
    const entry = currentTimeEntries.value.find((item) => item.id === timeEntryId);
    if (!entry?.end) {
        await deleteTimeEntryMutation(timeEntryId);
        return;
    }
    const url = entryUrl(timeEntryId);
    await notifications.handleApiRequestNotifications(() =>
        axios.delete(url, { params: { undoable: true } })
    );
    undoAction.value = {
        label: 'Time entry deleted',
        run: () => notifications.handleApiRequestNotifications(() => axios.post(url + '/restore')),
    };
    refreshEntryTotals();
}

async function createTag(name: string) {
    return await useTagsStore().createTag(name);
}

async function createProject(project: CreateProjectBody): Promise<Project | undefined> {
    return await useProjectsStore().createProject(project);
}

async function createClient(body: CreateClientBody): Promise<Client | undefined> {
    return await useClientsStore().createClient(body);
}

const { projects } = useProjectsQuery();
const { tasks } = useTasksQuery();
const { clients } = useClientsQuery();
const { tags } = useTagsQuery();

function onDatesChange({ start, end, view }: { start: Dayjs; end: Dayjs; view: CalendarView }) {
    calendarStart.value = start;
    calendarEnd.value = end;
    calendarView.value = view;
}

function onRefresh() {
    queryClient.invalidateQueries({
        queryKey: ['timeEntries'],
    });
    useCurrentTimeEntryStore().fetchCurrentTimeEntry();
}
</script>

<template>
    <AppLayout
        title="Calendar"
        data-testid="calendar_view"
        main-class="p-0 min-h-0 overflow-hidden">
        <p
            v-if="personalError || (isTimeGrid && timeEntriesError)"
            role="alert"
            class="border-b border-border px-6 py-2 text-sm text-destructive">
            {{
                personalError
                    ? 'Personal reminders and holidays could not be loaded.'
                    : 'Time entries could not be loaded.'
            }}
        </p>
        <ReminderDialog
            :show="reminderOpen"
            :date="reminderDate"
            :reminder="editingReminder"
            :busy="reminderBusy"
            :error="reminderError"
            @close="reminderOpen = false"
            @save="changeReminder"
            @remove="changeReminder()" />
        <div
            v-if="undoAction"
            class="fixed bottom-5 right-5 z-40 flex items-center gap-4 rounded-lg border border-border bg-card-background px-4 py-2 text-sm shadow-md">
            <span role="status">{{ undoAction.label }}</span>
            <button
                type="button"
                class="rounded px-2 py-1 font-semibold text-accent-400 hover:bg-secondary focus-visible:ring-2"
                :disabled="undoing"
                @click="undoLastChange">
                Undo
            </button>
            <button
                type="button"
                class="rounded px-2 py-1 text-text-secondary hover:bg-secondary"
                aria-label="Dismiss undo"
                :disabled="undoing"
                @click="undoAction = undefined">
                ×
            </button>
        </div>
        <TimeEntryCalendar
            :time-entries="
                isTimeGrid && (sources.entries || initialEntryId) ? currentTimeEntries : []
            "
            :projects="projects"
            :tasks="tasks"
            :clients="clients"
            :tags="tags"
            :loading="timeEntriesLoading"
            :enable-estimated-time="isAllowedToPerformPremiumAction()"
            :currency="getOrganizationCurrencyString()"
            :can-create-project="canCreateProjects()"
            :initial-date="initialDate"
            :initial-entry-id="initialEntryId"
            :initial-view="initialView"
            :organization-billable-rate="organization?.billable_rate ?? null"
            :create-time-entry="createTimeEntry"
            :update-time-entry="updateTimeEntry"
            :delete-time-entry="deleteTimeEntry"
            :create-client="createClient"
            :create-project="createProject"
            :create-tag="createTag"
            :activity-periods="testActivityPeriods"
            @dates-change="onDatesChange"
            @refresh="onRefresh">
            <template #actions="{ date }">
                <details
                    ref="sourceMenu"
                    class="relative"
                    @keydown.esc="sourceMenu && (sourceMenu.open = false)">
                    <summary
                        class="flex h-8 cursor-pointer list-none items-center gap-1.5 rounded-md border border-input bg-card-background px-2.5 text-xs font-medium hover:bg-secondary focus-visible:outline focus-visible:outline-2 focus-visible:outline-ring [&::-webkit-details-marker]:hidden">
                        Calendars <ChevronDown class="size-3.5" aria-hidden="true" />
                    </summary>
                    <div
                        class="absolute left-0 top-full z-30 mt-1 min-w-44 rounded-md border border-border bg-card-background p-2 text-sm shadow-md sm:left-auto sm:right-0">
                        <label
                            v-if="isTimeGrid"
                            class="flex items-center gap-2 rounded px-2 py-1.5">
                            <Checkbox
                                v-model:checked="sources.entries"
                                :class="sourceCheckboxClass" />
                            Time entries
                        </label>
                        <label class="flex items-center gap-2 rounded px-2 py-1.5">
                            <Checkbox
                                v-model:checked="sources.reminders"
                                :class="sourceCheckboxClass" />
                            Personal reminders
                        </label>
                        <label class="flex items-center gap-2 rounded px-2 py-1.5">
                            <Checkbox
                                v-model:checked="sources.holidays"
                                :class="sourceCheckboxClass" />
                            Chinese holidays
                        </label>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="mt-1 w-full justify-start"
                            :disabled="importing"
                            @click="importInput?.click()">
                            <Upload /> {{ importing ? 'Importing…' : 'Import holidays (.ics)' }}
                        </Button>
                        <input
                            ref="importInput"
                            type="file"
                            hidden
                            accept=".ics,text/calendar"
                            aria-label="Import holiday calendar file"
                            @change="importHolidays" />
                    </div>
                </details>
                <Button
                    size="sm"
                    class="size-8 px-0 bg-button-primary-background text-button-primary-text hover:bg-button-primary-background-hover sm:w-auto sm:px-3"
                    aria-label="New reminder"
                    @click="openReminder(date)">
                    <Plus aria-hidden="true" />
                    <span class="hidden sm:inline">Reminder</span>
                </Button>
            </template>
            <template #overview="{ view, date, navigate }">
                <CalendarOverview
                    v-if="view === 'month' || view === 'year'"
                    :view="view"
                    :date="date"
                    :items="calendarItems"
                    :loading="personalLoading"
                    :show-reminders="sources.reminders"
                    :show-holidays="sources.holidays"
                    @select="(selected) => navigate('month', selected)"
                    @month="
                        (month) => navigate('month', month.isSame(date, 'month') ? date : month)
                    "
                    @edit="(item) => openReminder(date, item)"
                    @add="openReminder" />
            </template>
            <template #day-items="{ date }">
                <div
                    v-if="calendarItems.get(calendarDateKey(date))?.length"
                    class="mt-1 flex flex-wrap justify-center gap-1">
                    <template
                        v-for="item in calendarItems.get(calendarDateKey(date))"
                        :key="item.key">
                        <button
                            v-if="item.reminder"
                            type="button"
                            class="flex max-w-full items-center gap-1 rounded bg-violet-500/10 px-1.5 py-0.5 text-xs hover:bg-violet-500/20"
                            @click="openReminder(date, item.reminder)">
                            <Bell class="size-3 shrink-0" aria-hidden="true" />
                            <span class="truncate">{{ item.time }} {{ item.title }}</span>
                        </button>
                        <span
                            v-else
                            class="max-w-full truncate rounded px-1.5 py-0.5 text-xs"
                            :class="
                                item.kind === 'workday' ? 'bg-amber-500/10' : 'bg-emerald-500/10'
                            "
                            :title="item.title"
                            >{{ item.title }}</span
                        >
                    </template>
                </div>
            </template>
        </TimeEntryCalendar>
    </AppLayout>
</template>
