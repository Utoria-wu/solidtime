<script setup lang="ts">
import { computed } from 'vue';
import { ChevronDown } from '@lucide/vue';
import { TagIcon } from '@heroicons/vue/20/solid';
import { Button } from '@/packages/ui/src/Buttons';
import { Field, FieldLabel } from '@/packages/ui/src/field';
import TagDropdown from '@/packages/ui/src/Tag/TagDropdown.vue';
import { canCreateTags } from '@/utils/permissions';
import { useTagsQuery } from '@/utils/useTagsQuery';
import { useTagsStore } from '@/utils/useTags';

const model = defineModel<string[]>({ default: () => [] });
const { tags } = useTagsQuery();
const { createTag } = useTagsStore();
const selectedNames = computed(() =>
    tags.value.filter((tag) => model.value.includes(tag.id)).map((tag) => tag.name)
);
</script>

<template>
    <Field>
        <FieldLabel :icon="TagIcon">Tags</FieldLabel>
        <TagDropdown
            v-model="model"
            :tags="tags"
            :create-tag="canCreateTags() ? createTag : undefined"
            :show-no-tag-option="false">
            <template #trigger>
                <Button
                    type="button"
                    variant="input"
                    aria-label="Project tags"
                    class="w-full justify-between">
                    <span
                        v-if="selectedNames.length"
                        class="truncate"
                        :title="selectedNames.join(', ')">
                        {{ selectedNames.join(', ') }}
                    </span>
                    <span v-else class="text-text-tertiary">Select tags</span>
                    <ChevronDown class="w-4 h-4 shrink-0 text-icon-default" />
                </Button>
            </template>
        </TagDropdown>
    </Field>
</template>
