<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTimeEntriesCalendarQuery } from '@/utils/useTimeEntriesCalendarQuery';
import { useTimeEntriesMutations } from '@/utils/useTimeEntriesMutations';
import { computed, ref, onMounted } from 'vue';
import { useEventListener } from '@vueuse/core';
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
import { getCurrentOrganizationId } from '@/utils/useUser';
import { useNotificationsStore } from '@/utils/notification';

const { organization } = useOrganizationQuery(getCurrentOrganizationId()!);
const calendarStart = ref<Dayjs | undefined>(undefined);
const calendarEnd = ref<Dayjs | undefined>(undefined);

// Optional deep link (e.g. "Fix in calendar") that opens the calendar on a specific day
const calendarLink = new URLSearchParams(window.location.search);
const initialDate = calendarLink.get('date');
const initialEntryId = calendarLink.get('entry');

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

const { data: timeEntryResponse, isLoading: timeEntriesLoading } = useTimeEntriesCalendarQuery(
    calendarStart,
    calendarEnd
);

const currentTimeEntries = computed(() => {
    return timeEntryResponse?.value?.data || [];
});
const undoAction = ref<{ label: string; run: () => Promise<unknown> }>();
const undoing = ref(false);
const notifications = useNotificationsStore();

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

const queryClient = useQueryClient();

function onDatesChange({ start, end }: { start: Dayjs; end: Dayjs }) {
    calendarStart.value = start;
    calendarEnd.value = end;
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
            :time-entries="currentTimeEntries"
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
            :organization-billable-rate="organization?.billable_rate ?? null"
            :create-time-entry="createTimeEntry"
            :update-time-entry="updateTimeEntry"
            :delete-time-entry="deleteTimeEntry"
            :create-client="createClient"
            :create-project="createProject"
            :create-tag="createTag"
            :activity-periods="testActivityPeriods"
            @dates-change="onDatesChange"
            @refresh="onRefresh" />
    </AppLayout>
</template>
