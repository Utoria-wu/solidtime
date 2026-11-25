<script setup lang="ts">
import { computed, ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { marked } from 'marked';
import DOMPurify from 'dompurify';
import {
    AdjustmentsHorizontalIcon,
    ArrowPathIcon,
    CheckCircleIcon,
    ChevronDownIcon,
    ChevronRightIcon,
    ClockIcon,
    DocumentTextIcon,
} from '@heroicons/vue/20/solid';
import { api, type Task, type TimeEntry } from '@/packages/api/src';
import {
    historyKinds,
    useTaskCategories,
    useTaskHistory,
    type HistoryEntry,
} from '@/utils/useTaskHistory';
import { canCreateTags, canUpdateTags, canViewAllTimeEntries } from '@/utils/permissions';
import { getCurrentMembershipId, getCurrentOrganizationId } from '@/utils/useUser';
import { getLocalizedDayJs } from '@/packages/ui/src/utils/time';
import TaskCategoryManager from './TaskCategoryManager.vue';

const props = defineProps<{ projectId: string; tasks: Task[] }>();
const { categories, isError: categoryError } = useTaskCategories();
const legacy = useTaskHistory(props.projectId);
const search = ref('');
const selectedTask = ref<string | null>(null);
const groupBy = ref<'category' | 'stage'>('category');
const collapsed = ref(new Set<string>());
const manage = ref(false);
const categoryMap = computed(() => new Map(categories.value.map((c) => [c.id, c])));
const taskMap = computed(() => new Map(props.tasks.map((task) => [task.id, task])));
const calendarKey = computed(
    () =>
        [
            'timeEntries',
            'project-history',
            getCurrentOrganizationId(),
            props.projectId,
            canViewAllTimeEntries() ? null : getCurrentMembershipId(),
        ] as const
);
const calendar = useQuery({
    queryKey: calendarKey,
    enabled: () => Boolean(getCurrentOrganizationId() && getCurrentMembershipId()),
    queryFn: async ({ queryKey: [, , organization, project, member], signal }) => {
        if (!organization) throw new Error('No organization');
        const entries: TimeEntry[] = [];
        for (;;) {
            const page = await api.getTimeEntries({
                params: { organization },
                queries: {
                    project_ids: [project],
                    member_id: member ?? undefined,
                    offset: entries.length || undefined,
                },
                signal,
            });
            entries.push(...page.data);
            if (!page.data.length || entries.length >= page.meta.total) return entries;
        }
    },
});

type Record = {
    id: string;
    task: Task;
    date: string;
    label: string;
    note: string | null;
    icon: typeof ClockIcon;
    color: string;
    calendarUrl?: string;
    legacy?: HistoryEntry;
};

const records = computed(() => {
    const result: Record[] = [];
    for (const task of props.tasks) {
        if (task.created_at)
            result.push({
                id: `created-${task.id}`,
                task,
                date: task.created_at,
                label: 'Created',
                note: task.markdown_notes ?? null,
                icon: DocumentTextIcon,
                color: '#5389b0',
            });
        if (task.done_at)
            result.push({
                id: `completed-${task.id}`,
                task,
                date: task.done_at,
                label: 'Completed',
                note: task.completion_notes ?? null,
                icon: CheckCircleIcon,
                color: '#4e936d',
            });
    }
    for (const event of calendar.data.value ?? []) {
        const task = event.task_id ? taskMap.value.get(event.task_id) : undefined;
        if (!task || !event.end) continue;
        const query = new URLSearchParams({
            date: getLocalizedDayJs(event.start).format('YYYY-MM-DD'),
            entry: event.id,
        });
        result.push({
            id: event.id,
            task,
            date: event.start,
            label: 'Calendar',
            note: event.markdown_notes ?? null,
            icon: ClockIcon,
            color: '#789487',
            calendarUrl: '/calendar?' + query.toString(),
        });
    }
    for (const entry of legacy.entries.value) {
        const task = taskMap.value.get(entry.task_id);
        if (!task) continue;
        result.push({
            id: entry.id,
            task,
            date: entry.occurred_at,
            label: historyKinds[entry.kind].label,
            note: entry.markdown_notes,
            icon: ArrowPathIcon,
            color: historyKinds[entry.kind].color,
            legacy: entry,
        });
    }
    return result.sort((a, b) => Date.parse(a.date) - Date.parse(b.date));
});

const taskCounts = computed(() => {
    const counts = new Map<string, number>();
    for (const record of records.value)
        counts.set(record.task.id, (counts.get(record.task.id) ?? 0) + 1);
    return counts;
});

const days = computed(() => {
    const result = new Map<string, Record[]>();
    const term = search.value.trim().toLocaleLowerCase();
    for (const record of records.value) {
        if (selectedTask.value && record.task.id !== selectedTask.value) continue;
        if (
            term &&
            !`${record.task.name} ${record.note ?? ''} ${record.label}`
                .toLocaleLowerCase()
                .includes(term)
        )
            continue;
        const date = getLocalizedDayJs(record.date).format('YYYY-MM-DD');
        const day = result.get(date) ?? [];
        day.push(record);
        result.set(date, day);
    }
    return result;
});
const groups = computed(() => {
    const result = new Map<string, { name: string; color: string; tasks: Task[]; order: number }>();
    for (const task of props.tasks) {
        const category = categoryMap.value.get(task.category_id ?? '');
        const id = groupBy.value === 'category' ? (task.category_id ?? '') : (task.stage ?? '');
        const group = result.get(id) ?? {
            name:
                groupBy.value === 'category'
                    ? (category?.name ?? 'Uncategorized')
                    : task.stage || 'No stage',
            color: category?.color ?? '#9ca3af',
            tasks: [],
            order: category?.sort_order ?? 1000001,
        };
        group.tasks.push(task);
        result.set(id, group);
    }
    return [...result].sort((a, b) =>
        groupBy.value === 'category'
            ? a[1].order - b[1].order || a[1].name.localeCompare(b[1].name)
            : a[1].name.localeCompare(b[1].name)
    );
});
function toggle(id: string) {
    const next = new Set(collapsed.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    collapsed.value = next;
}
function notesHtml(notes: string | null) {
    return DOMPurify.sanitize(
        marked.parse(notes ?? '', { async: false, gfm: true, breaks: true }),
        {
            USE_PROFILES: { html: true },
            FORBID_TAGS: ['img', 'style', 'form', 'textarea', 'button', 'select'],
            FORBID_ATTR: ['style'],
        }
    );
}
</script>

<template>
    <div data-testid="project_history" class="overflow-hidden">
        <header
            class="flex flex-wrap items-center justify-between gap-3 border-b border-default-background-separator px-5 py-4">
            <h2 class="font-semibold">Project history</h2>
            <input
                v-model="search"
                aria-label="Search history"
                type="search"
                placeholder="Search history…"
                class="w-44 rounded-lg border border-default-background-separator bg-transparent px-3 py-2 text-sm" />
        </header>
        <div class="history-layout">
            <aside class="history-explorer border-r border-default-background-separator">
                <div class="flex items-center justify-between px-4 py-4">
                    <select
                        v-model="groupBy"
                        aria-label="Group tasks"
                        class="rounded-md border border-default-background-separator bg-default-background px-2 py-1.5 text-xs font-medium text-text-secondary">
                        <option value="category">BY CATEGORY</option>
                        <option value="stage">BY STAGE</option></select
                    ><button
                        v-if="canCreateTags() || canUpdateTags()"
                        type="button"
                        aria-label="Manage categories"
                        title="Manage categories"
                        class="rounded p-1 text-text-secondary hover:bg-tertiary"
                        @click="manage = true">
                        <AdjustmentsHorizontalIcon class="size-4" />
                    </button>
                </div>
                <button
                    class="mx-2 flex w-[calc(100%-1rem)] items-center justify-between rounded-lg px-3 py-2 text-left text-sm"
                    :class="!selectedTask ? 'bg-tertiary font-medium' : 'text-text-secondary'"
                    @click="selectedTask = null">
                    All tasks<span class="text-xs">{{ tasks.length }}</span>
                </button>
                <div v-for="[id, group] in groups" :key="id" class="mt-4">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 px-4 py-2 text-left text-xs font-medium"
                        :aria-expanded="!collapsed.has(id)"
                        @click="toggle(id)">
                        <component
                            :is="collapsed.has(id) ? ChevronRightIcon : ChevronDownIcon"
                            class="size-3" /><span
                            class="size-2 rounded-full"
                            :style="{ background: group.color }" /><span class="flex-1 truncate">{{
                            group.name
                        }}</span
                        ><span class="text-text-secondary">{{ group.tasks.length }}</span>
                    </button>
                    <div
                        v-if="!collapsed.has(id)"
                        class="ml-6 border-l border-default-background-separator">
                        <button
                            v-for="task in group.tasks"
                            :key="task.id"
                            type="button"
                            class="my-0.5 flex w-full items-start gap-2 rounded-r-lg py-2 pl-3 pr-3 text-left text-sm"
                            :class="
                                selectedTask === task.id
                                    ? 'bg-tertiary font-medium'
                                    : 'text-text-secondary hover:bg-tertiary'
                            "
                            @click="selectedTask = task.id">
                            <span
                                class="mt-1 size-1.5 shrink-0 rounded-full"
                                :style="{ background: group.color }" /><span class="flex-1"
                                ><span class="line-clamp-2">{{ task.name }}</span
                                ><span
                                    v-if="task.is_done || task.is_canceled"
                                    class="mt-1 block text-[10px] font-normal"
                                    >{{ task.is_canceled ? 'Canceled' : 'Done' }}</span
                                ></span
                            ><span class="text-xs">{{ taskCounts.get(task.id) ?? 0 }}</span>
                        </button>
                    </div>
                </div>
                <p v-if="categoryError" class="px-4 py-3 text-xs text-text-secondary">
                    Category labels could not be loaded.
                </p>
            </aside>

            <section class="history-timeline min-w-0">
                <p
                    v-if="calendar.isError.value || legacy.isError.value"
                    role="alert"
                    class="px-5 py-3 text-sm text-text-secondary">
                    Some history could not be loaded.
                    <button
                        type="button"
                        class="underline"
                        @click="
                            calendar.refetch();
                            legacy.refetch();
                        ">
                        Retry
                    </button>
                </p>
                <p
                    v-if="!days.size && (calendar.isPending.value || legacy.isPending.value)"
                    class="px-5 py-10 text-center text-sm text-text-secondary">
                    Loading history…
                </p>
                <p
                    v-else-if="!days.size"
                    class="px-5 py-10 text-center text-sm text-text-secondary">
                    {{ search.trim() ? 'No matching records' : 'No task activity yet' }}
                </p>
                <section v-for="[date, day] in days" :key="date" class="px-5 pb-3 pt-5">
                    <h3 class="mb-4 text-xs font-medium text-text-secondary">
                        {{ getLocalizedDayJs(day[0]!.date).format('ddd, MMM D, YYYY') }}
                    </h3>
                    <div class="timeline-spine">
                        <article
                            v-for="record in day"
                            :id="'history-' + record.id"
                            :key="record.id"
                            class="record-wrap"
                            :style="{
                                '--record-color': record.color,
                                '--record-indent':
                                    record.legacy?.parent_id || record.legacy?.issue_id
                                        ? '14px'
                                        : '0px',
                                marginLeft: 'var(--record-indent)',
                            }">
                            <div class="record-card">
                                <span class="record-icon"
                                    ><component :is="record.icon" class="size-4"
                                /></span>
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="flex flex-wrap items-center gap-2 text-xs text-text-secondary">
                                        <span>{{ record.label }}</span>
                                        <time :datetime="record.date">{{
                                            getLocalizedDayJs(record.date).format('HH:mm')
                                        }}</time>
                                        <a
                                            v-if="record.calendarUrl"
                                            :href="record.calendarUrl"
                                            class="underline underline-offset-2"
                                            >Open in Calendar</a
                                        >
                                    </div>
                                    <div class="mt-1 break-words text-sm font-medium">
                                        {{ record.task.name }}
                                    </div>
                                    <div
                                        v-if="record.note"
                                        class="history-markdown mt-2 text-sm"
                                        v-html="notesHtml(record.note)" />
                                    <a
                                        v-if="record.legacy?.parent_id"
                                        :href="'#history-' + record.legacy.parent_id"
                                        class="text-xs text-text-secondary underline"
                                        >Previous record</a
                                    >
                                </div>
                            </div>
                        </article>
                    </div>
                </section>
            </section>
        </div>
    </div>
    <TaskCategoryManager v-model:show="manage" />
</template>

<style scoped>
.history-layout {
    display: grid;
    grid-template-columns: 230px minmax(0, 1fr);
    min-height: 600px;
}
.history-explorer {
    padding-bottom: 24px;
}
.timeline-spine {
    margin-left: 15px;
    border-left: 1px solid color-mix(in srgb, #9ca3af 28%, transparent);
    padding-left: 20px;
}
.record-wrap {
    position: relative;
    padding-bottom: 8px;
}
.record-wrap:before {
    content: '';
    position: absolute;
    top: 24px;
    left: calc(-21px - var(--record-indent, 0px));
    width: calc(20px + var(--record-indent, 0px));
    border-top: 1px solid color-mix(in srgb, var(--record-color) 25%, transparent);
}
.record-card {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    text-align: left;
    width: 100%;
    padding: 10px 12px;
    border: 0;
    border-radius: 6px;
    background: transparent;
    transition: background-color 0.15s;
}
.record-card:hover {
    background: color-mix(in srgb, var(--record-color) 4%, transparent);
}
.record-card:focus-visible {
    background: color-mix(in srgb, var(--record-color) 6%, transparent);
}
.record-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    flex-shrink: 0;
    color: var(--record-color);
    border-radius: 50%;
    background: color-mix(in srgb, var(--record-color) 10%, transparent);
}
.history-markdown :deep(h1),
.history-markdown :deep(h2),
.history-markdown :deep(h3) {
    font-size: 1em;
    font-weight: 600;
    margin: 1em 0 0.5em;
}
.history-markdown :deep(p) {
    margin-bottom: 0.75em;
    overflow-wrap: anywhere;
}
.history-markdown :deep(ul) {
    list-style: disc;
    padding-left: 1.2em;
}
.history-markdown :deep(ol) {
    list-style: decimal;
    padding-left: 1.2em;
}
.history-markdown :deep(a) {
    text-decoration: underline;
}
.history-markdown :deep(pre) {
    padding: 10px;
    border-radius: 6px;
    overflow: auto;
    background: #9ca3af15;
}
.history-markdown :deep(blockquote) {
    border-left: 2px solid #9ca3af55;
    padding-left: 12px;
}
.history-markdown :deep(input) {
    margin-right: 6px;
    accent-color: #5c9a7c;
}
.history-markdown :deep(ul:has(> li > input[type='checkbox'])) {
    list-style: none;
    padding-left: 0;
}
@media (max-width: 1250px) {
    .history-layout {
        grid-template-columns: 200px minmax(0, 1fr);
    }
}
@media (max-width: 700px) {
    .history-layout {
        display: block;
    }
    .history-explorer {
        max-height: 220px;
        overflow: auto;
        border-right: 0;
        border-bottom: 1px solid #9ca3af33;
    }
    .timeline-spine {
        padding-left: 10px;
    }
    .record-wrap:before {
        width: calc(10px + var(--record-indent, 0px));
        left: calc(-11px - var(--record-indent, 0px));
    }
}
</style>
