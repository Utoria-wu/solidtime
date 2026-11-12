<script setup lang="ts">
import { computed, inject, type ComputedRef } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { XMarkIcon, ArrowTopRightOnSquareIcon } from '@heroicons/vue/20/solid';
import { marked } from 'marked';
import DOMPurify from 'dompurify';
import { api, type Organization, type Task, type TimeEntry } from '@/packages/api/src';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/packages/ui/src/dialog';
import { Button } from '@/packages/ui/src/Buttons';
import LoadingSpinner from '@/packages/ui/src/LoadingSpinner.vue';
import { formatHumanReadableDuration, getLocalizedDayJs } from '@/packages/ui/src/utils/time';
import { getCurrentMembershipId, getCurrentOrganizationId } from '@/utils/useUser';
import { useProjectsQuery } from '@/utils/useProjectsQuery';

const props = defineProps<{ task: Task }>();
const open = defineModel<boolean>('open', { required: true });
const organization = inject<ComputedRef<Organization>>('organization');
const { projects } = useProjectsQuery();
const project = computed(() => projects.value.find((item) => item.id === props.task.project_id));
const queryKey = computed(
    () =>
        [
            'timeEntries',
            'task-activity',
            getCurrentOrganizationId(),
            getCurrentMembershipId(),
            props.task.id,
        ] as const
);
const { data, isPending, isError, refetch } = useQuery({
    queryKey,
    enabled: computed(
        () => open.value && Boolean(getCurrentOrganizationId() && getCurrentMembershipId())
    ),
    queryFn: async ({ queryKey: [, , organizationId, memberId, taskId], signal }) => {
        if (!organizationId || !memberId) throw new Error('No membership');
        const entries: TimeEntry[] = [];
        while (true) {
            const response = await api.getTimeEntries({
                params: { organization: organizationId },
                queries: {
                    task_ids: [taskId],
                    member_id: memberId,
                    offset: entries.length || undefined,
                },
                signal,
            });
            entries.push(...response.data);
            if (!response.data.length || entries.length >= response.meta.total) return entries;
        }
    },
});
function duration(seconds: number) {
    if (seconds > 0 && seconds < 60) return '<1 min';
    return formatHumanReadableDuration(
        seconds,
        organization?.value.interval_format,
        organization?.value.number_format
    );
}
function renderNotes(notes: string | null | undefined) {
    return DOMPurify.sanitize(
        marked.parse(notes ?? '', { async: false, gfm: true, breaks: true }),
        {
            USE_PROFILES: { html: true },
            FORBID_TAGS: ['img', 'style', 'form', 'textarea', 'button', 'select'],
            FORBID_ATTR: ['style'],
        }
    );
}
const taskNotes = computed(() =>
    typeof props.task.markdown_notes === 'string' && props.task.markdown_notes.trim()
        ? renderNotes(props.task.markdown_notes)
        : ''
);
const entries = computed(() =>
    [...(data.value ?? [])].reverse().map((entry) => {
        const start = getLocalizedDayJs(entry.start);
        const end = entry.end ? getLocalizedDayJs(entry.end) : null;
        const query = new URLSearchParams({ date: start.format('YYYY-MM-DD'), entry: entry.id });
        return {
            ...entry,
            startLabel: start.format('HH:mm'),
            endLabel: end?.format(start.isSame(end, 'day') ? 'HH:mm' : 'MMM D, HH:mm'),
            day: start.format('dddd, MMM D, YYYY'),
            url: '/calendar?' + query.toString(),
            notesHtml: renderNotes(entry.markdown_notes),
        };
    })
);
const total = computed(() => entries.value.reduce((sum, entry) => sum + (entry.duration ?? 0), 0));
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="fixed inset-y-0 right-0 my-0 md:my-0 xl:my-0 block h-dvh w-full max-w-xl overflow-y-auto rounded-none">
            <header class="border-b border-default-background-separator px-6 py-5">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2 text-sm text-text-secondary">
                        <span
                            v-if="project"
                            class="size-2.5 rounded-full"
                            :style="{ backgroundColor: project.color }" />
                        {{ project?.name ?? 'Project' }}
                    </div>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Close task details"
                        @click="open = false">
                        <XMarkIcon class="size-5" />
                    </Button>
                </div>
                <DialogTitle class="mt-2 text-xl font-semibold text-text-primary">{{
                    task.name
                }}</DialogTitle>
                <DialogDescription class="mt-3 flex items-center gap-3 text-xs text-text-secondary">
                    <span
                        class="rounded-md bg-secondary px-2 py-1 font-medium"
                        :class="{ 'text-green-600': task.is_done }"
                        >{{ task.is_done ? 'Done' : 'Active' }}</span
                    >
                    <span v-if="data">{{ duration(total) }} tracked</span>
                </DialogDescription>
            </header>
            <div class="px-6 py-5">
                <section
                    v-if="taskNotes"
                    class="border-b border-default-background-separator pb-5 mb-5">
                    <h3 class="mb-2 text-sm font-semibold">Task notes</h3>
                    <div
                        class="notes-preview break-words text-sm text-text-secondary"
                        v-html="taskNotes" />
                </section>
                <section aria-label="Activity">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold">Activity</h3>
                        <span v-if="data" class="text-xs text-text-secondary"
                            >{{ entries.length }}
                            {{ entries.length === 1 ? 'time entry' : 'time entries' }}</span
                        >
                    </div>
                    <div v-if="isPending" class="flex justify-center py-8"><LoadingSpinner /></div>
                    <div v-else-if="isError" class="py-4 text-sm text-text-secondary" role="alert">
                        Could not load time entries.
                        <Button variant="ghost" size="sm" @click="refetch()">Retry</Button>
                    </div>
                    <p v-else-if="!entries.length" class="py-4 text-sm text-text-secondary">
                        You have no time entries linked to this task.
                    </p>
                    <template v-for="(entry, index) in entries" :key="entry.id">
                        <p
                            v-if="entry.day !== entries[index - 1]?.day"
                            class="mb-2 mt-4 text-xs font-medium text-text-secondary">
                            {{ entry.day }}
                        </p>
                        <article
                            class="mb-3 rounded-lg border border-default-background-separator px-4 py-3">
                            <div class="flex items-start justify-between gap-3">
                                <h4 class="min-w-0 break-words text-sm font-medium">
                                    {{ entry.description || 'Time entry' }}
                                </h4>
                                <span class="shrink-0 text-xs font-medium">{{
                                    entry.end ? duration(entry.duration ?? 0) : 'Running'
                                }}</span>
                            </div>
                            <p class="mt-1 text-xs text-text-secondary">
                                {{ entry.startLabel
                                }}{{ entry.endLabel ? '–' + entry.endLabel : '' }}
                            </p>
                            <div
                                v-if="entry.notesHtml"
                                class="notes-preview mt-3 break-words text-sm text-text-secondary"
                                v-html="entry.notesHtml" />
                            <a
                                :href="entry.url"
                                class="mt-3 inline-flex items-center gap-1 text-xs font-medium text-accent-400 hover:underline">
                                Open in Calendar <ArrowTopRightOnSquareIcon class="size-3" />
                            </a>
                        </article>
                    </template>
                </section>
            </div>
        </DialogContent>
    </Dialog>
</template>

<style scoped>
.notes-preview :deep(h1),
.notes-preview :deep(h2),
.notes-preview :deep(h3) {
    margin-bottom: 0.75rem;
    font-size: 1rem;
    font-weight: 600;
}
.notes-preview :deep(p),
.notes-preview :deep(ul),
.notes-preview :deep(ol) {
    margin-bottom: 0.75rem;
    line-height: 1.75;
}
.notes-preview :deep(ul) {
    padding-left: 1.25rem;
    list-style-type: disc;
}
.notes-preview :deep(ol) {
    padding-left: 1.25rem;
    list-style-type: decimal;
}
.notes-preview :deep(ul:has(> li > input[type='checkbox'])) {
    padding-left: 0;
    list-style: none;
}
.notes-preview :deep(input[type='checkbox']) {
    margin-right: 0.5rem;
}
.notes-preview :deep(a) {
    text-decoration: underline;
}
.notes-preview :deep(code) {
    font-family: monospace;
}
.notes-preview :deep(pre) {
    overflow-x: auto;
    padding: 0.75rem;
}
.notes-preview :deep(blockquote) {
    border-left: 2px solid currentColor;
    padding-left: 0.75rem;
}
.notes-preview :deep(:last-child) {
    margin-bottom: 0;
}
</style>
