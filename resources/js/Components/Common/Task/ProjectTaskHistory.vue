<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { marked } from 'marked';
import DOMPurify from 'dompurify';
import {
    PlusIcon,
    ChevronDownIcon,
    ChevronRightIcon,
    AdjustmentsHorizontalIcon,
    ArrowLongRightIcon,
    XMarkIcon,
    PencilSquareIcon,
    LinkIcon,
    CheckCircleIcon,
    ExclamationCircleIcon,
    BeakerIcon,
    LightBulbIcon,
    MagnifyingGlassIcon,
    ArrowPathIcon,
    BoltIcon,
} from '@heroicons/vue/20/solid';
import type { Task } from '@/packages/api/src';
import {
    historyKinds,
    useTaskCategories,
    useTaskHistory,
    type HistoryEntry,
} from '@/utils/useTaskHistory';
import { canCreateTags, canUpdateTags, canUpdateTasks } from '@/utils/permissions';
import { getLocalizedDayJs } from '@/packages/ui/src/utils/time';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import TaskCategoryManager from './TaskCategoryManager.vue';
import TaskHistoryRecordModal from './TaskHistoryRecordModal.vue';
const props = defineProps<{ projectId: string; tasks: Task[] }>();
const { categories, isError: categoryError } = useTaskCategories();
const { entries, isPending, isError, refetch, save, remove } = useTaskHistory(props.projectId);
const search = ref('');
const selectedTask = ref<string | null>(null);
const selectedId = ref<string | null>(null);
const groupBy = ref<'category' | 'stage'>('category');
const filter = ref('all');
const collapsed = ref(new Set<string>());
const manage = ref(false);
const modal = ref(false);
const original = ref<HistoryEntry>();
const preceding = ref<HistoryEntry>();
const deleteConfirm = ref(false);
const deleting = ref(false);
const categoryMap = computed(() => new Map(categories.value.map((c) => [c.id, c])));
const taskMap = computed(() => new Map(props.tasks.map((t) => [t.id, t])));
const resolved = (entry: HistoryEntry) =>
    entry.outcome === 'resolved' ||
    entry.outcome === 'verified' ||
    entries.value.some(
        (e) => e.issue_id === entry.id && (e.outcome === 'resolved' || e.outcome === 'verified')
    );
const taskCounts = computed(() => {
    const counts = new Map<string, number>();
    for (const entry of entries.value)
        counts.set(entry.task_id, (counts.get(entry.task_id) ?? 0) + 1);
    return counts;
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
const visible = computed(() =>
    entries.value.filter((entry) => {
        if (selectedTask.value && selectedTask.value !== entry.task_id) return false;
        if (filter.value === 'open' && !(entry.kind === 'problem' && !resolved(entry)))
            return false;
        if (filter.value === 'resolved' && !(entry.kind === 'problem' && resolved(entry)))
            return false;
        const text =
            `${entry.title} ${entry.markdown_notes ?? ''} ${taskMap.value.get(entry.task_id)?.name ?? ''}`.toLocaleLowerCase();
        return !search.value.trim() || text.includes(search.value.trim().toLocaleLowerCase());
    })
);
const days = computed(() => {
    const result: { date: string; label: string; records: HistoryEntry[] }[] = [];
    for (const entry of visible.value) {
        const day = getLocalizedDayJs(entry.occurred_at);
        const date = day.format('YYYY-MM-DD');
        let group = result.at(-1);
        if (group?.date !== date) {
            group = { date, label: day.format('ddd, MMM D, YYYY'), records: [] };
            result.push(group);
        }
        group!.records.push(entry);
    }
    return result;
});
const selected = computed(() => entries.value.find((e) => e.id === selectedId.value));
watch(selectedId, () => {
    deleteConfirm.value = false;
});
const openCount = computed(
    () => entries.value.filter((e) => e.kind === 'problem' && !resolved(e)).length
);
const solvedCount = computed(
    () => entries.value.filter((e) => e.kind === 'problem' && resolved(e)).length
);
const icons = {
    progress: ArrowPathIcon,
    problem: ExclamationCircleIcon,
    investigation: MagnifyingGlassIcon,
    attempt: BeakerIcon,
    finding: LightBulbIcon,
    action: BoltIcon,
    verification: CheckCircleIcon,
};
function toggle(id: string) {
    const next = new Set(collapsed.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    collapsed.value = next;
}
function add(parent?: HistoryEntry) {
    original.value = undefined;
    preceding.value = parent;
    modal.value = true;
}
function edit(entry: HistoryEntry) {
    original.value = entry;
    preceding.value = undefined;
    modal.value = true;
}
function depth(entry: HistoryEntry) {
    let value = 0;
    let cursor = entry.parent_id;
    const visited = new Set<string>();
    const byId = new Map(entries.value.map((e) => [e.id, e]));
    while (cursor && !visited.has(cursor)) {
        visited.add(cursor);
        value++;
        cursor = byId.get(cursor)?.parent_id ?? null;
    }
    return Math.min(value || (entry.issue_id ? 1 : 0), 3);
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
function linked(id: string | null) {
    return entries.value.find((e) => e.id === id);
}
function calendarUrl(entry: HistoryEntry) {
    return (
        '/calendar?' +
        new URLSearchParams({
            date: getLocalizedDayJs(entry.source!.start).format('YYYY-MM-DD'),
            entry: entry.source!.id,
        }).toString()
    );
}
async function destroy() {
    if (!selected.value || deleting.value) return;
    deleting.value = true;
    try {
        if (await remove(selected.value)) selectedId.value = null;
    } finally {
        deleting.value = false;
    }
}
</script>
<template>
    <div
        data-testid="project_history"
        class="history-shell overflow-hidden rounded-xl border border-default-background-separator bg-default-background">
        <div
            class="flex flex-wrap items-center justify-between gap-3 border-b border-default-background-separator px-5 py-4">
            <div>
                <h2 class="font-semibold">Project history</h2>
                <p class="mt-1 text-xs text-text-secondary">
                    {{ entries.length }} records <span class="px-1.5">·</span> {{ openCount }} open
                    problems <span class="px-1.5">·</span> {{ solvedCount }} resolved
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <input
                    v-model="search"
                    aria-label="Search history"
                    type="search"
                    placeholder="Search history…"
                    class="w-44 rounded-lg border border-default-background-separator bg-transparent px-3 py-2 text-sm" /><select
                    v-model="filter"
                    aria-label="Filter history"
                    class="rounded-lg border border-default-background-separator bg-default-background px-2 py-2 text-sm">
                    <option value="all">All records</option>
                    <option value="open">Open problems</option>
                    <option value="resolved">Resolved problems</option></select
                ><SecondaryButton
                    v-if="canUpdateTasks() && tasks.length"
                    :icon="PlusIcon"
                    @click="add()"
                    >Add record</SecondaryButton
                >
            </div>
        </div>
        <div class="history-layout" :class="{ 'has-detail': selected }">
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
                            @click="
                                selectedTask = task.id;
                                selectedId = null;
                            ">
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
            <main class="history-timeline min-w-0">
                <div
                    class="flex items-center justify-between border-b border-default-background-separator px-5 py-3 text-xs text-text-secondary">
                    <span>{{
                        selectedTask ? taskMap.get(selectedTask)?.name : 'All project activity'
                    }}</span
                    ><span>Oldest → newest</span>
                </div>
                <p v-if="isPending" class="p-10 text-center text-sm text-text-secondary">
                    Loading project history…
                </p>
                <div v-else-if="isError" class="p-10 text-center text-sm">
                    <p>Could not load project history.</p>
                    <button class="mt-3 underline" @click="refetch()">Try again</button>
                </div>
                <div v-else-if="!visible.length" class="px-8 py-20 text-center">
                    <ArrowPathIcon class="mx-auto mb-4 size-7 text-icon-default" />
                    <h3 class="font-medium">
                        {{ entries.length ? 'No matching records' : 'A clear story starts here' }}
                    </h3>
                    <p class="mx-auto mt-2 max-w-sm text-sm leading-6 text-text-secondary">
                        {{
                            entries.length
                                ? 'Choose another task or adjust your filters.'
                                : 'Capture progress, connect problems to attempts, and keep the outcome alongside the work.'
                        }}
                    </p>
                    <SecondaryButton
                        v-if="canUpdateTasks() && tasks.length && !entries.length"
                        class="mt-5"
                        :icon="PlusIcon"
                        @click="add()"
                        >Add your first record</SecondaryButton
                    >
                </div>
                <section v-for="day in days" :key="day.date" class="px-5 pb-3 pt-5">
                    <h3 class="mb-4 text-xs font-medium text-text-secondary">{{ day.label }}</h3>
                    <div class="timeline-spine">
                        <div
                            v-for="entry in day.records"
                            :key="entry.id"
                            :style="{
                                '--record-color': historyKinds[entry.kind].color,
                                '--record-indent': depth(entry) * 14 + 'px',
                                marginLeft: depth(entry) * 14 + 'px',
                            }"
                            class="record-wrap">
                            <button
                                type="button"
                                class="record-card group"
                                :class="{ selected: selectedId === entry.id }"
                                @click="selectedId = entry.id">
                                <span class="record-icon"
                                    ><component :is="icons[entry.kind]" class="size-4"
                                /></span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2 text-[11px]">
                                        <span
                                            class="font-medium"
                                            :style="{ color: historyKinds[entry.kind].color }"
                                            >{{ historyKinds[entry.kind].label }}</span
                                        ><span
                                            v-if="entry.outcome === 'unsuccessful'"
                                            class="outcome unsuccessful"
                                            >Unsuccessful</span
                                        ><span
                                            v-else-if="entry.kind === 'problem' && resolved(entry)"
                                            class="outcome resolved"
                                            >Resolved</span
                                        ><span v-else-if="entry.outcome" class="outcome resolved">{{
                                            entry.outcome === 'verified' ? 'Verified' : 'Resolved'
                                        }}</span
                                        ><span
                                            v-if="entry.source"
                                            class="ml-auto flex items-center gap-1 text-text-secondary"
                                            ><LinkIcon class="size-3" />Calendar</span
                                        >
                                    </div>
                                    <p
                                        class="mt-1.5 break-words text-sm font-medium text-text-primary">
                                        {{ entry.title }}
                                    </p>
                                    <p
                                        v-if="!selectedTask"
                                        class="mt-1.5 truncate text-xs text-text-secondary">
                                        {{ taskMap.get(entry.task_id)?.name }}
                                    </p>
                                    <p
                                        v-if="entry.parent_id && linked(entry.parent_id)"
                                        class="mt-2 flex items-center gap-1.5 truncate text-[11px] text-text-secondary">
                                        <ArrowLongRightIcon class="size-3.5 shrink-0" /><span
                                            class="truncate"
                                            >{{ linked(entry.parent_id)?.title }}</span
                                        >
                                    </p>
                                </div>
                                <time class="shrink-0 text-[11px] text-text-secondary">{{
                                    getLocalizedDayJs(entry.occurred_at).format('HH:mm')
                                }}</time>
                            </button>
                        </div>
                    </div>
                </section>
            </main>
            <aside
                v-if="selected"
                class="history-inspector border-l border-default-background-separator p-5">
                <div class="flex items-center justify-between text-xs text-text-secondary">
                    <span>RECORD DETAILS</span
                    ><button
                        type="button"
                        aria-label="Close record details"
                        @click="selectedId = null">
                        <XMarkIcon class="size-4" />
                    </button>
                </div>
                <div
                    class="mt-6 flex items-center gap-2 text-xs font-medium"
                    :style="{ color: historyKinds[selected.kind].color }">
                    <component :is="icons[selected.kind]" class="size-4" />{{
                        historyKinds[selected.kind].label
                    }}
                </div>
                <h3 class="mt-3 break-words text-lg font-semibold leading-7">
                    {{ selected.title }}
                </h3>
                <p class="mt-3 text-xs leading-5 text-text-secondary">
                    {{ getLocalizedDayJs(selected.occurred_at).format('MMM D, YYYY · HH:mm')
                    }}<br />{{ selected.author_name ?? 'Former member' }}
                </p>
                <dl
                    class="mt-5 space-y-3 border-y border-default-background-separator py-4 text-xs">
                    <div>
                        <dt class="mb-1 text-text-secondary">Task</dt>
                        <dd>{{ taskMap.get(selected.task_id)?.name }}</dd>
                    </div>
                    <div v-if="selected.issue_id">
                        <dt class="mb-1 text-text-secondary">Related problem</dt>
                        <dd>
                            <button
                                class="text-left underline decoration-dotted"
                                @click="selectedId = selected.issue_id">
                                {{ linked(selected.issue_id)?.title ?? 'Unavailable record' }}
                            </button>
                        </dd>
                    </div>
                    <div v-if="selected.parent_id">
                        <dt class="mb-1 text-text-secondary">Follows</dt>
                        <dd>
                            <button
                                class="text-left underline decoration-dotted"
                                @click="selectedId = selected.parent_id">
                                {{ linked(selected.parent_id)?.title ?? 'Unavailable record' }}
                            </button>
                        </dd>
                    </div>
                    <div v-if="selected.outcome">
                        <dt class="mb-1 text-text-secondary">Outcome</dt>
                        <dd>
                            {{
                                selected.outcome === 'verified'
                                    ? 'Verified'
                                    : selected.outcome === 'resolved'
                                      ? 'Resolved'
                                      : 'Unsuccessful'
                            }}
                        </dd>
                    </div>
                </dl>
                <div
                    v-if="selected.markdown_notes"
                    class="history-markdown mt-5 text-sm leading-6"
                    v-html="notesHtml(selected.markdown_notes)" />
                <p v-else class="mt-5 text-sm text-text-secondary">No notes added.</p>
                <a
                    v-if="selected.source"
                    :href="calendarUrl(selected)"
                    class="mt-6 flex items-start gap-2 rounded-lg border border-default-background-separator p-3 text-xs leading-5"
                    ><LinkIcon class="mt-0.5 size-4 shrink-0 text-icon-default" /><span
                        >Calendar source<br /><span class="font-medium">{{
                            selected.source.description || 'Untitled event'
                        }}</span></span
                    ></a
                >
                <p v-else-if="selected.time_entry_id" class="mt-5 text-xs text-text-secondary">
                    The linked Calendar event is unavailable.
                </p>
                <div v-if="canUpdateTasks()" class="mt-6 flex flex-wrap gap-2">
                    <SecondaryButton :icon="PlusIcon" @click="add(selected)"
                        >Continue thread</SecondaryButton
                    ><SecondaryButton :icon="PencilSquareIcon" @click="edit(selected)"
                        >Edit</SecondaryButton
                    >
                </div>
                <div v-if="canUpdateTasks()" class="mt-5">
                    <button
                        v-if="!deleteConfirm"
                        class="text-xs text-text-secondary underline"
                        @click="deleteConfirm = true">
                        Delete record
                    </button>
                    <div v-else class="text-xs">
                        <p class="mb-2">
                            Delete this record? Records with dependent links must be relinked first.
                        </p>
                        <button
                            class="mr-3 font-medium text-red-600"
                            :disabled="deleting"
                            @click="destroy">
                            {{ deleting ? 'Deleting…' : 'Delete' }}</button
                        ><button @click="deleteConfirm = false">Keep record</button>
                    </div>
                </div>
            </aside>
        </div>
    </div>
    <TaskCategoryManager v-model:show="manage" />
    <TaskHistoryRecordModal
        v-model:show="modal"
        :tasks="tasks"
        :entries="entries"
        :initial-task="selectedTask ?? undefined"
        :original="original"
        :preceding="preceding"
        :save="save"
        @saved="selectedId = $event.id" />
</template>
<style scoped>
.history-layout {
    display: grid;
    grid-template-columns: 230px minmax(0, 1fr);
    min-height: 600px;
}
.history-layout.has-detail {
    grid-template-columns: 230px minmax(300px, 1fr) 320px;
}
.history-explorer {
    padding-bottom: 24px;
}
.history-timeline {
    background: color-mix(in srgb, var(--theme-color-default-background, white) 97%, #789da8);
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
.record-card.selected {
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
.outcome {
    border-radius: 4px;
    padding: 1px 5px;
    font-size: 10px;
}
.resolved {
    color: #39825d;
    background: #58a97a14;
}
.unsuccessful {
    color: #ac694f;
    background: #c9775714;
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
    .history-layout.has-detail {
        grid-template-columns: 200px minmax(280px, 1fr);
    }
    .history-inspector {
        grid-column: 1 / -1;
        border-left: 0;
        border-top: 1px solid #9ca3af33;
    }
    .history-layout {
        grid-template-columns: 200px minmax(0, 1fr);
    }
}
@media (max-width: 700px) {
    .history-layout,
    .history-layout.has-detail {
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
