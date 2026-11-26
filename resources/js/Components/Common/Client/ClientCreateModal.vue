<script setup lang="ts">
import TextInput from '@/packages/ui/src/Input/TextInput.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import { ref } from 'vue';
import type { CreateClientBody } from '@/packages/api/src';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import { useFocus } from '@vueuse/core';
import { useClientsStore } from '@/utils/useClients';
import { Field, FieldGroup, FieldLabel } from '@/packages/ui/src/field';
import TimeEntryNotesField from '@/packages/ui/src/TimeEntry/TimeEntryNotesField.vue';

const { createClient } = useClientsStore();
const show = defineModel('show', { default: false });
const saving = ref(false);

const client = ref<CreateClientBody>({
    name: '',
    markdown_notes: null,
});

async function submit() {
    if (saving.value) return;
    saving.value = true;
    try {
        const result = await createClient(client.value);
        if (result) {
            client.value = { name: '', markdown_notes: null };
            show.value = false;
        }
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
                <span> Create Client </span>
            </div>
        </template>

        <template #content>
            <FieldGroup>
                <Field>
                    <FieldLabel for="clientName">Client Name</FieldLabel>
                    <TextInput
                        id="clientName"
                        ref="clientNameInput"
                        v-model="client.name"
                        type="text"
                        placeholder="Client Name"
                        class="block w-full"
                        required
                        autocomplete="off"
                        @keydown.enter="submit" />
                </Field>
                <TimeEntryNotesField v-model="client.markdown_notes" label="Client notes" />
            </FieldGroup>
        </template>
        <template #footer>
            <SecondaryButton @click="show = false"> Cancel </SecondaryButton>

            <PrimaryButton
                class="ms-3"
                :class="{ 'opacity-25': saving }"
                :disabled="saving"
                @click="submit">
                Create Client
            </PrimaryButton>
        </template>
    </DialogModal>
</template>

<style scoped></style>
