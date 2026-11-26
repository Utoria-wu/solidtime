<script setup lang="ts">
import TextInput from '@/packages/ui/src/Input/TextInput.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import { ref, watch } from 'vue';
import type { Client, UpdateClientBody } from '@/packages/api/src';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import { useFocus } from '@vueuse/core';
import { useClientsStore } from '@/utils/useClients';
import { Field, FieldGroup, FieldLabel } from '@/packages/ui/src/field';
import TimeEntryNotesField from '@/packages/ui/src/TimeEntry/TimeEntryNotesField.vue';

const { updateClient } = useClientsStore();
const show = defineModel('show', { default: false });
const saving = ref(false);

const props = defineProps<{
    client: Client;
}>();

const name = ref(props.client.name);
const initialNotes = ref(props.client.markdown_notes ?? null);
const notes = ref(initialNotes.value);

watch(show, (isOpen) => {
    if (!isOpen) return;
    name.value = props.client.name;
    initialNotes.value = props.client.markdown_notes ?? null;
    notes.value = initialNotes.value;
});

async function submit() {
    if (saving.value) return;
    const body: UpdateClientBody = { name: name.value };
    if (notes.value !== initialNotes.value) body.markdown_notes = notes.value;
    saving.value = true;
    try {
        const result = await updateClient(props.client.id, body);
        if (result) show.value = false;
    } finally {
        saving.value = false;
    }
}

const clientNameInput = ref<HTMLInputElement | null>(null);
useFocus(clientNameInput, { initialValue: true });
</script>

<template>
    <DialogModal closeable :show="show" @close="show = false" @submit="submit">
        <template #title>
            <div class="flex space-x-2">
                <span> Update Client </span>
            </div>
        </template>

        <template #content>
            <FieldGroup>
                <Field>
                    <FieldLabel for="clientName">Client Name</FieldLabel>
                    <TextInput
                        id="clientName"
                        ref="clientNameInput"
                        v-model="name"
                        type="text"
                        placeholder="Client Name"
                        class="block w-full"
                        required
                        autocomplete="off"
                        @keydown.enter="submit" />
                </Field>
                <TimeEntryNotesField v-model="notes" label="Client notes" />
            </FieldGroup>
        </template>
        <template #footer>
            <SecondaryButton @click="show = false"> Cancel </SecondaryButton>

            <PrimaryButton
                class="ms-3"
                :class="{ 'opacity-25': saving }"
                :disabled="saving"
                @click="submit">
                Update Client
            </PrimaryButton>
        </template>
    </DialogModal>
</template>

<style scoped></style>
