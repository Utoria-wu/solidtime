<script setup lang="ts">
import {
    TrashIcon,
    PauseIcon,
    PencilSquareIcon,
    CheckCircleIcon,
    XCircleIcon,
    ArrowUturnLeftIcon,
} from '@heroicons/vue/20/solid';
import type { Task } from '@/packages/api/src';
import { canDeleteTasks, canUpdateTasks } from '@/utils/permissions';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/packages/ui/src';

const emit = defineEmits<{
    pause: [];
    delete: [];
    edit: [];
    done: [];
    cancel: [];
}>();
const props = defineProps<{
    task: Task;
    canPauseTimer?: boolean;
    busy?: boolean;
}>();
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                class="inline-flex size-8 items-center justify-center rounded-md text-text-secondary hover:bg-tertiary focus-visible:ring-2 focus-visible:ring-ring"
                :disabled="busy"
                :aria-label="'Actions for Task ' + props.task.name">
                <svg class="size-5" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path
                        fill="none"
                        stroke="currentColor"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="1.5"
                        d="M12 5.92A.96.96 0 1 0 12 4a.96.96 0 0 0 0 1.92m0 7.04a.96.96 0 1 0 0-1.92a.96.96 0 0 0 0 1.92M12 20a.96.96 0 1 0 0-1.92a.96.96 0 0 0 0 1.92" />
                </svg>
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent class="min-w-[150px]" align="end">
            <DropdownMenuItem
                v-if="canPauseTimer"
                class="flex items-center gap-3 cursor-pointer"
                @click="emit('pause')">
                <PauseIcon class="w-5 text-icon-active" />
                <span>Pause and save to Calendar</span>
            </DropdownMenuItem>
            <DropdownMenuItem
                v-if="canUpdateTasks() && !props.task.is_canceled"
                :aria-label="
                    'Mark Task ' +
                    props.task.name +
                    (props.task.is_done ? ' as active' : ' as done')
                "
                class="flex items-center space-x-3 cursor-pointer"
                @click="emit('done')">
                <CheckCircleIcon class="w-5 text-icon-active" />
                <span v-if="props.task.is_done">Mark as active</span>
                <span v-else>Mark as done</span>
            </DropdownMenuItem>
            <DropdownMenuSeparator
                v-if="canUpdateTasks() && (canPauseTimer || !task.is_canceled)" />
            <DropdownMenuItem
                v-if="canUpdateTasks()"
                :aria-label="'Edit Task ' + props.task.name"
                data-testid="task_edit"
                class="flex items-center space-x-3 cursor-pointer"
                @click="emit('edit')">
                <PencilSquareIcon class="w-5 text-icon-active" />
                <span>Edit</span>
            </DropdownMenuItem>
            <DropdownMenuItem
                v-if="canUpdateTasks() && !props.task.is_done"
                class="flex items-center space-x-3 cursor-pointer"
                @click="emit('cancel')">
                <ArrowUturnLeftIcon v-if="props.task.is_canceled" class="w-5 text-icon-active" />
                <XCircleIcon v-else class="w-5 text-icon-active" />
                <span>{{ props.task.is_canceled ? 'Restore task' : 'Cancel' }}</span>
            </DropdownMenuItem>
            <DropdownMenuItem
                v-if="canDeleteTasks()"
                :aria-label="'Delete Task ' + props.task.name"
                data-testid="task_delete"
                class="flex items-center space-x-3 cursor-pointer text-destructive focus:text-destructive"
                @click="emit('delete')">
                <TrashIcon class="w-5" />
                <span>Delete</span>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>

<style scoped></style>
