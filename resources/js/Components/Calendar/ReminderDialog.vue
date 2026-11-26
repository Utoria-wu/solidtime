<script setup lang="ts">
import { ref, watch } from 'vue';
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import { Button } from '@/packages/ui/src/Buttons';
import TextInput from '@/packages/ui/src/Input/TextInput.vue';
import type { Reminder, ReminderFields } from './personalCalendar';

const props = defineProps<{
    show: boolean;
    date: string;
    reminder: Reminder | null;
    busy: boolean;
    error: string;
}>();
const emit = defineEmits<{
    close: [];
    save: [fields: ReminderFields];
    remove: [];
}>();
const title = ref('');
const formDate = ref('');
const time = ref('');
const confirming = ref(false);

watch(
    () => [props.show, props.reminder, props.date] as const,
    ([show, reminder, selected]) => {
        if (!show) return;
        title.value = reminder?.title ?? '';
        formDate.value = reminder?.date ?? selected;
        time.value = reminder?.time ?? '';
        confirming.value = false;
    }
);

function save() {
    if (props.busy || confirming.value || !title.value.trim() || !formDate.value) return;
    emit('save', { title: title.value.trim(), date: formDate.value, time: time.value || null });
}
</script>

<template>
    <DialogModal
        :show="show"
        :closeable="!busy"
        max-width="md"
        @close="emit('close')"
        @submit="save">
        <template #title>{{ reminder ? 'Edit reminder' : 'New reminder' }}</template>
        <template #content>
            <div v-if="confirming" class="text-sm">Delete this personal reminder?</div>
            <div v-else class="space-y-4">
                <label class="block text-sm font-medium">
                    Title
                    <TextInput
                        v-model="title"
                        class="mt-1 block w-full"
                        maxlength="160"
                        required
                        autocomplete="off"
                        @keydown.enter="save" />
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="min-w-0 text-sm font-medium">
                        Date
                        <TextInput
                            v-model="formDate"
                            type="date"
                            required
                            class="mt-1 block w-full min-w-0" />
                    </label>
                    <label class="min-w-0 text-sm font-medium">
                        Time <span class="font-normal text-text-secondary">(optional)</span>
                        <TextInput v-model="time" type="time" class="mt-1 block w-full min-w-0" />
                    </label>
                </div>
            </div>
            <p v-if="error" role="alert" class="mt-3 text-sm text-destructive">{{ error }}</p>
        </template>
        <template #footer>
            <div class="flex w-full flex-wrap justify-end gap-2">
                <Button
                    v-if="reminder && !confirming"
                    type="button"
                    variant="ghost"
                    class="mr-auto text-destructive"
                    :disabled="busy"
                    @click="confirming = true">
                    Delete
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="busy"
                    @click="confirming ? (confirming = false) : emit('close')"
                    >Cancel</Button
                >
                <Button
                    v-if="confirming"
                    type="button"
                    variant="destructive"
                    :disabled="busy"
                    @click="emit('remove')"
                    >Delete reminder</Button
                >
                <Button
                    v-else
                    type="button"
                    class="bg-button-primary-background text-button-primary-text hover:bg-button-primary-background-hover"
                    :disabled="busy || !title.trim() || !formDate"
                    @click="save"
                    >Save reminder</Button
                >
            </div>
        </template>
    </DialogModal>
</template>
