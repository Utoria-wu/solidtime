<script setup lang="ts">
import { ref } from 'vue';
import { useTaskCategories, type TaskCategory } from '@/utils/useTaskHistory';
import { canCreateTags, canUpdateTags } from '@/utils/permissions';
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import TextInput from '@/packages/ui/src/Input/TextInput.vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
const show = defineModel<boolean>('show', { default: false });
const { categories, save } = useTaskCategories();
const editing = ref<string>();
const name = ref('');
const color = ref('#5389b0');
const order = ref(0);
const archived = ref(false);
const busy = ref(false);
function edit(item?: TaskCategory) {
    editing.value = item?.id;
    name.value = item?.name ?? '';
    color.value = item?.color ?? '#5389b0';
    order.value = item?.sort_order ?? categories.value.length;
    archived.value = item?.is_archived ?? false;
}
async function submit() {
    if (busy.value || !name.value.trim()) return;
    busy.value = true;
    try {
        if (
            await save(
                {
                    name: name.value.trim(),
                    color: color.value,
                    sort_order: order.value,
                    is_archived: archived.value,
                },
                editing.value
            )
        )
            edit();
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <DialogModal :show="show" closeable @close="show = false" @submit="submit">
        <template #title>Manage task categories</template>
        <template #content>
            <p class="mb-4 text-sm text-text-secondary">
                Shared across this organization. Archiving keeps existing task assignments.
            </p>
            <div
                class="mb-5 max-h-52 overflow-auto rounded-lg border border-default-background-separator">
                <button
                    v-for="item in categories"
                    :key="item.id"
                    type="button"
                    class="flex w-full items-center gap-3 border-b border-default-background-separator px-4 py-3 text-left last:border-0 hover:bg-tertiary"
                    :disabled="!canUpdateTags()"
                    @click="edit(item)">
                    <span class="size-2.5 rounded-full" :style="{ background: item.color }" />
                    <span class="flex-1">{{ item.name }}</span
                    ><span class="text-xs text-text-secondary">{{
                        item.is_archived ? 'Archived' : 'Order ' + item.sort_order
                    }}</span>
                </button>
                <p v-if="!categories.length" class="p-4 text-sm text-text-secondary">
                    No categories yet. Create your first category below.
                </p>
            </div>
            <form
                v-if="editing ? canUpdateTags() : canCreateTags()"
                class="space-y-4"
                @submit.prevent="submit">
                <div class="flex items-center justify-between">
                    <h3 class="font-medium">{{ editing ? 'Edit category' : 'New category' }}</h3>
                    <button
                        v-if="editing && canCreateTags()"
                        type="button"
                        class="text-sm text-text-secondary"
                        @click="edit()">
                        + New category
                    </button>
                </div>
                <label class="block text-sm"
                    >Name<TextInput
                        v-model="name"
                        class="mt-2 w-full"
                        required
                        maxlength="100"
                        autocomplete="off"
                /></label>
                <div class="flex items-end gap-5">
                    <label class="text-sm"
                        >Color<input
                            v-model="color"
                            type="color"
                            class="mt-2 block h-10 w-16 cursor-pointer rounded border border-default-background-separator" /></label
                    ><label class="text-sm"
                        >Order<input
                            v-model.number="order"
                            type="number"
                            min="0"
                            max="1000000"
                            class="mt-2 block w-24 rounded-lg border border-default-background-separator bg-default-background p-2" /></label
                    ><label class="pb-2 text-sm"
                        ><input v-model="archived" type="checkbox" class="mr-2" />Archived</label
                    >
                </div>
            </form>
        </template>
        <template #footer
            ><SecondaryButton @click="show = false">Close</SecondaryButton
            ><PrimaryButton
                v-if="editing ? canUpdateTags() : canCreateTags()"
                class="ml-3"
                :disabled="busy || !name.trim()"
                @click="submit"
                >{{ busy ? 'Saving…' : 'Save category' }}</PrimaryButton
            ></template
        >
    </DialogModal>
</template>
