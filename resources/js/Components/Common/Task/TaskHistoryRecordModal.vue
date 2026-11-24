<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { api, type Task, type TimeEntry } from '@/packages/api/src';
import { getCurrentOrganizationId, getCurrentMembershipId } from '@/utils/useUser';
import { getLocalizedDayJs } from '@/packages/ui/src/utils/time';
import {
    historyKinds,
    type HistoryEntry,
    type HistoryBody,
    type HistoryKind,
} from '@/utils/useTaskHistory';
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import TextInput from '@/packages/ui/src/Input/TextInput.vue';
import TimeEntryNotesField from '@/packages/ui/src/TimeEntry/TimeEntryNotesField.vue';
const props = defineProps<{
    tasks: Task[];
    entries: HistoryEntry[];
    initialTask?: string;
    original?: HistoryEntry;
    preceding?: HistoryEntry;
    save: (body: HistoryBody, id?: string) => Promise<HistoryEntry | undefined>;
}>();
const show = defineModel<boolean>('show', { default: false });
const emit = defineEmits<{ saved: [entry: HistoryEntry] }>();
const taskId = ref('');
const title = ref('');
const kind = ref<HistoryKind>('progress');
const notes = ref<string | null>(null);
const occurred = ref('');
const issueId = ref<string | null>(null);
const parentId = ref<string | null>(null);
const sourceId = ref<string | null>(null);
const outcome = ref<HistoryEntry['outcome']>(null);
const busy = ref(false);
watch(show, (open) => {
    if (!open) return;
    const e = props.original;
    taskId.value =
        e?.task_id ?? props.preceding?.task_id ?? props.initialTask ?? props.tasks[0]?.id ?? '';
    title.value = e?.title ?? '';
    kind.value = e?.kind ?? 'progress';
    notes.value = e?.markdown_notes ?? null;
    occurred.value = getLocalizedDayJs(e?.occurred_at).format('YYYY-MM-DDTHH:mm');
    issueId.value =
        e?.issue_id ??
        (props.preceding?.kind === 'problem' ? props.preceding.id : props.preceding?.issue_id) ??
        null;
    parentId.value = e?.parent_id ?? props.preceding?.id ?? null;
    sourceId.value = e?.time_entry_id ?? null;
    outcome.value = e?.outcome ?? null;
});
watch(
    taskId,
    (_, old) => {
        if (old && !props.original) {
            issueId.value = null;
            parentId.value = null;
            sourceId.value = null;
        }
    },
    { flush: 'sync' }
);
watch(kind, (value) => {
    if (value === 'problem') {
        issueId.value = null;
        parentId.value = null;
    }
});
const taskEntries = computed(() =>
    props.entries.filter((e) => e.task_id === taskId.value && e.id !== props.original?.id)
);
const problems = computed(() => taskEntries.value.filter((e) => e.kind === 'problem'));
const parents = computed(() =>
    taskEntries.value.filter((e) => e.id === issueId.value || e.issue_id === issueId.value)
);
watch(issueId, () => {
    if (parentId.value && !parents.value.some((e) => e.id === parentId.value))
        parentId.value = null;
});
const events = useQuery({
    queryKey: computed(() => [
        'timeEntries',
        'history-sources',
        getCurrentOrganizationId(),
        getCurrentMembershipId(),
        taskId.value,
    ]),
    enabled: () => show.value && !!taskId.value,
    queryFn: async ({ signal }) => {
        const result: TimeEntry[] = [];
        for (;;) {
            const page = await api.getTimeEntries({
                params: { organization: getCurrentOrganizationId()! },
                queries: {
                    task_ids: [taskId.value],
                    member_id: getCurrentMembershipId()!,
                    offset: result.length || undefined,
                },
                signal,
            });
            result.push(...page.data);
            if (!page.data.length || result.length >= page.meta.total) return result;
        }
    },
});
function useSource() {
    const event = events.data.value?.find((e) => e.id === sourceId.value);
    if (!event) return;
    if (event.description) title.value = event.description;
    notes.value = event.markdown_notes ?? null;
    occurred.value = getLocalizedDayJs(event.start).format('YYYY-MM-DDTHH:mm');
}
async function submit() {
    if (busy.value || !taskId.value || !title.value.trim() || !occurred.value) return;
    busy.value = true;
    try {
        const result = await props.save(
            {
                task_id: taskId.value,
                title: title.value.trim(),
                kind: kind.value,
                markdown_notes: notes.value,
                occurred_at: getLocalizedDayJs(occurred.value).toISOString(),
                issue_id: issueId.value,
                parent_id: parentId.value,
                time_entry_id: sourceId.value,
                outcome: outcome.value,
                version: props.original?.version ?? 0,
            },
            props.original?.id
        );
        if (result) {
            emit('saved', result);
            show.value = false;
        }
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <DialogModal :show="show" closeable @close="show = false" @submit="submit">
        <template #title>{{ original ? 'Edit history record' : 'Add history record' }}</template>
        <template #content>
            <div class="history-form space-y-4">
                <label
                    >Task<select v-model="taskId" aria-label="Task" :disabled="!!original">
                        <option v-for="task in tasks" :key="task.id" :value="task.id">
                            {{ task.name }}
                        </option>
                    </select></label
                >
                <div class="grid grid-cols-2 gap-4">
                    <label
                        >Record type<select v-model="kind" aria-label="Record type">
                            <option v-for="(item, key) in historyKinds" :key="key" :value="key">
                                {{ item.label }}
                            </option>
                        </select></label
                    ><label
                        >Occurred at<input v-model="occurred" type="datetime-local" required
                    /></label>
                </div>
                <label
                    >Title<TextInput
                        v-model="title"
                        class="mt-2 block w-full"
                        required
                        maxlength="500"
                        autocomplete="off"
                        placeholder="What happened?"
                /></label>
                <div v-if="kind !== 'problem'" class="grid grid-cols-2 gap-4">
                    <label
                        >Related problem<select v-model="issueId" aria-label="Related problem">
                            <option :value="null">No problem linked</option>
                            <option v-for="item in problems" :key="item.id" :value="item.id">
                                {{ item.title }}
                            </option>
                        </select></label
                    ><label
                        >Follows record<select v-model="parentId" aria-label="Follows record">
                            <option :value="null">No preceding record</option>
                            <option v-for="item in parents" :key="item.id" :value="item.id">
                                {{ item.title }}
                            </option>
                        </select></label
                    >
                </div>
                <label
                    >Outcome<select v-model="outcome" aria-label="Outcome">
                        <option :value="null">No outcome yet</option>
                        <option value="unsuccessful">Unsuccessful — keep investigating</option>
                        <option value="resolved">Resolved</option>
                        <option value="verified">Verified</option>
                    </select></label
                >
                <TimeEntryNotesField v-model="notes" label="Notes" />
                <div class="rounded-lg border border-default-background-separator p-3">
                    <label
                        >Calendar source · your task events<select
                            v-model="sourceId"
                            aria-label="Calendar source">
                            <option :value="null">No Calendar event linked</option>
                            <option
                                v-if="
                                    original?.time_entry_id &&
                                    !events.data.value?.some(
                                        (e) => e.id === original?.time_entry_id
                                    )
                                "
                                :value="original.time_entry_id">
                                Previously linked event
                            </option>
                            <option
                                v-for="event in events.data.value"
                                :key="event.id"
                                :value="event.id">
                                {{ getLocalizedDayJs(event.start).format('MMM D, HH:mm') }} ·
                                {{ event.description || 'Untitled event' }}
                            </option>
                        </select></label
                    >
                    <p v-if="events.isError.value" class="mt-2 text-xs text-text-secondary">
                        Could not load Calendar events. You can still save this record.
                    </p>
                    <button
                        v-if="sourceId"
                        type="button"
                        class="mt-2 text-sm text-text-secondary underline"
                        @click="useSource">
                        Copy event title, notes and date
                    </button>
                </div>
            </div>
        </template>
        <template #footer
            ><SecondaryButton @click="show = false">Cancel</SecondaryButton
            ><PrimaryButton
                class="ml-3"
                :disabled="busy || !title.trim() || !taskId || !occurred"
                @click="submit"
                >{{ busy ? 'Saving…' : 'Save record' }}</PrimaryButton
            ></template
        >
    </DialogModal>
</template>
<style scoped>
.history-form label {
    display: block;
    font-size: 0.875rem;
}
.history-form select,
.history-form input[type='datetime-local'] {
    display: block;
    width: 100%;
    margin-top: 0.5rem;
    padding: 0.625rem 0.75rem;
    border: 1px solid var(--theme-color-default-background-separator, #e4e4e7);
    border-radius: 0.5rem;
    background: transparent;
    font-size: 0.875rem;
}
</style>
