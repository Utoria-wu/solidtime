<script setup lang="ts">
import TextInput from '@/packages/ui/src/Input/TextInput.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import { computed, nextTick, ref, watch } from 'vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import TimeTrackerProjectTaskDropdown from '@/packages/ui/src/TimeTracker/TimeTrackerProjectTaskDropdown.vue';
import { TagIcon } from '@heroicons/vue/20/solid';
import { getDayJsInstance, getLocalizedDayJs } from '@/packages/ui/src/utils/time';
import type {
    CreateClientBody,
    CreateProjectBody,
    Project,
    Client,
    CreateTimeEntryBody,
} from '@/packages/api/src';
import TagDropdown from '@/packages/ui/src/Tag/TagDropdown.vue';
import BillableIcon from '@/packages/ui/src/Icons/BillableIcon.vue';
import { useBillableRatesLock } from '@/packages/ui/src/utils/useBillableRatesLock';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '..';
import { Button } from '@/packages/ui/src/Buttons';
import TimeRangeFields from '@/packages/ui/src/TimeEntry/TimeRangeFields.vue';
import type { Tag, Task } from '@/packages/api/src';
import { Field, FieldLabel } from '../field';
import { useTasksStore } from '@/utils/useTasks';
import { canUpdateTasks } from '@/utils/permissions';

const show = defineModel('show', { default: false });
const { locked: billableRatesLocked } = useBillableRatesLock();
const saving = ref(false);
const { updateTask } = useTasksStore();
const taskStatus = ref('active');
const taskStatusChanged = ref(false);
const entryCreated = ref(false);

const props = defineProps<{
    enableEstimatedTime: boolean;
    createTimeEntry: (entry: Omit<CreateTimeEntryBody, 'member_id'>) => Promise<void>;
    createClient: (client: CreateClientBody) => Promise<Client | undefined>;
    createProject: (project: CreateProjectBody) => Promise<Project | undefined>;
    createTag: (name: string) => Promise<Tag | undefined>;
    tags: Tag[];
    projects: Project[];
    tasks: Task[];
    clients: Client[];
    start?: string;
    end?: string;
    currency: string;
    organizationBillableRate: number | null;
    canCreateProject: boolean;
}>();

const description = ref<HTMLInputElement | null>(null);

watch(show, (value) => {
    if (value) {
        timeEntry.value = { ...timeEntryDefaultValues, tags: [] };
        localStart.value = getLocalizedDayJs(props.start ?? timeEntryDefaultValues.start).format();
        localEnd.value = getLocalizedDayJs(props.end ?? timeEntryDefaultValues.end).format();
        entryCreated.value = false;
        nextTick(() => {
            description.value?.focus();
        });
    }
});

const timeEntryDefaultValues = {
    description: '',
    project_id: null,
    task_id: null,
    tags: [],
    billable: false,
    type: 'work' as CreateTimeEntryBody['type'],
    start: getDayJsInstance().utc().subtract(1, 'h').second(0).format(),
    end: getDayJsInstance().utc().second(0).format(),
};

const timeEntry = ref({
    ...timeEntryDefaultValues,
});
const selectedTask = computed(() =>
    props.tasks.find(
        (task) =>
            task.id === timeEntry.value.task_id && task.project_id === timeEntry.value.project_id
    )
);
watch(
    [show, () => selectedTask.value?.id],
    () => {
        taskStatus.value = selectedTask.value?.is_done ? 'done' : 'active';
        taskStatusChanged.value = false;
    },
    { immediate: true }
);
watch(
    () => selectedTask.value?.is_done,
    (isDone) => {
        if (!taskStatusChanged.value) taskStatus.value = isDone ? 'done' : 'active';
    }
);

// update the localStart and localEnd when props.start or props.end get updates
watch(
    () => props.start,
    (value) => {
        if (value) {
            localStart.value = getLocalizedDayJs(value).format();
        }
    }
);
watch(
    () => props.end,
    (value) => {
        if (value) {
            localEnd.value = getLocalizedDayJs(value).format();
        }
    }
);

watch(
    () => timeEntry.value.project_id,
    (value) => {
        if (value) {
            // check if project is billable by default and set billable accordingly
            const project = props.projects.find((p) => p.id === value);
            if (project) {
                timeEntry.value.billable = project.is_billable;
            }
        }
    }
);

const localStart = ref(getLocalizedDayJs(timeEntryDefaultValues.start).format());

const localEnd = ref(getLocalizedDayJs(timeEntryDefaultValues.end).format());

watch(localStart, (value) => {
    timeEntry.value.start = getLocalizedDayJs(value).utc().format();
});

watch(localEnd, (value) => {
    timeEntry.value.end = getLocalizedDayJs(value).utc().format();
});

async function submit() {
    if (saving.value) return;
    const task = selectedTask.value;
    const isDone = taskStatus.value === 'done';
    const shouldUpdateTask =
        task && canUpdateTasks() && taskStatusChanged.value && task.is_done !== isDone;
    saving.value = true;
    try {
        if (!entryCreated.value) {
            await props.createTimeEntry({ ...timeEntry.value, tags: [...timeEntry.value.tags] });
            entryCreated.value = true;
        }
        if (shouldUpdateTask) {
            await updateTask(task.id, { name: task.name, is_done: isDone });
        }
        show.value = false;
    } finally {
        saving.value = false;
    }
}

const billableProxy = computed({
    get: () => (timeEntry.value.billable ? 'true' : 'false'),
    set: (value: string) => {
        timeEntry.value.billable = value === 'true';
    },
});
</script>

<template>
    <DialogModal :closeable="!saving" :show="show" @close="show = false" @submit="submit">
        <template #title>
            <div class="flex space-x-2">
                <span> Create manual time entry </span>
            </div>
        </template>

        <template #content>
            <p v-if="entryCreated && !saving" role="alert" class="mb-4 text-text-secondary">
                Time entry saved. Task status was not updated. Retry to save the task status.
            </p>
            <fieldset :disabled="saving || entryCreated" class="contents">
                <div class="sm:flex items-end space-y-2 sm:space-y-0 sm:space-x-4">
                    <div class="flex-1">
                        <TextInput
                            id="description"
                            ref="description"
                            v-model="timeEntry.description"
                            aria-label="Description"
                            placeholder="What did you work on?"
                            type="text"
                            class="mt-1 block w-full"
                            @keydown.enter="submit" />
                    </div>
                </div>
                <div class="flex flex-col sm:flex-row sm:items-end gap-2 pt-4">
                    <div class="flex-1 min-w-0">
                        <TimeTrackerProjectTaskDropdown
                            v-model:project="timeEntry.project_id"
                            v-model:task="timeEntry.task_id"
                            variant="input"
                            size="default"
                            :clients
                            :create-project
                            :create-client
                            :can-create-project
                            :currency
                            :organization-billable-rate="organizationBillableRate"
                            :projects="projects"
                            :tasks="tasks"
                            :enable-estimated-time="enableEstimatedTime" />
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <TagDropdown
                            v-model="timeEntry.tags"
                            :create-tag
                            :tags="tags"
                            :show-no-tag-option="false">
                            <template #trigger>
                                <Button variant="input">
                                    <TagIcon class="h-4 text-icon-default" />
                                    <span>{{
                                        timeEntry.tags.length === 0
                                            ? 'Tags'
                                            : `${timeEntry.tags.length} Tag${timeEntry.tags.length > 1 ? 's' : ''}`
                                    }}</span>
                                </Button>
                            </template>
                        </TagDropdown>
                        <Select v-model="billableProxy">
                            <SelectTrigger :show-chevron="false">
                                <SelectValue class="flex items-center gap-2">
                                    <BillableIcon class="h-4 text-icon-default" />
                                    <span>{{
                                        timeEntry.billable ? 'Billable' : 'Non-Billable'
                                    }}</span>
                                </SelectValue>
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="true" :disabled="billableRatesLocked"
                                    >Billable</SelectItem
                                >
                                <SelectItem value="false">Non Billable</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>
                <Field v-if="selectedTask && canUpdateTasks()" class="pt-4 sm:max-w-48">
                    <FieldLabel>Task status</FieldLabel>
                    <Select
                        v-model="taskStatus"
                        :disabled="saving || entryCreated"
                        @update:model-value="taskStatusChanged = true">
                        <SelectTrigger aria-label="Task status">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="done">Done</SelectItem>
                        </SelectContent>
                    </Select>
                </Field>
                <TimeRangeFields
                    v-model:start="localStart"
                    v-model:end="localEnd"
                    show-hint
                    class="pt-4"></TimeRangeFields>
            </fieldset>
        </template>
        <template #footer>
            <SecondaryButton tabindex="2" :disabled="saving" @click="show = false">
                Cancel</SecondaryButton
            >
            <PrimaryButton
                tabindex="2"
                class="ms-3"
                :class="{ 'opacity-25': saving }"
                :disabled="saving"
                @click="submit">
                {{
                    saving ? 'Saving...' : entryCreated ? 'Retry task status' : 'Create Time Entry'
                }}
            </PrimaryButton>
        </template>
    </DialogModal>
</template>

<style scoped></style>
