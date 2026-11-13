<script setup lang="ts">
import { useInterfaceLanguage } from '@/utils/useInterfaceLanguage';
import TableHeading from '@/Components/Common/TableHeading.vue';
import SortableTableHeaderCell from '@/Components/Common/SortableTableHeaderCell.vue';
import type { SortColumn, SortDirection } from '@/Components/Common/Task/TaskTable.vue';

const props = defineProps<{
    sortColumn: SortColumn;
    sortDirection: SortDirection;
    descFirstColumns: ReadonlySet<SortColumn>;
}>();

defineEmits<{
    sort: [column: SortColumn];
}>();
const { t } = useInterfaceLanguage();
</script>

<template>
    <TableHeading>
        <SortableTableHeaderCell
            class="pr-3 pl-4 sm:pl-6 lg:pl-8 3xl:pl-12"
            column="name"
            v-bind="props"
            @sort="$emit('sort', $event)">
            {{ t('Task Name') }}
        </SortableTableHeaderCell>
        <SortableTableHeaderCell
            class="whitespace-nowrap"
            column="created_at"
            v-bind="props"
            @sort="$emit('sort', $event)">
            {{ t('Created') }}
        </SortableTableHeaderCell>
        <SortableTableHeaderCell
            class="whitespace-nowrap"
            column="done_at"
            v-bind="props"
            @sort="$emit('sort', $event)">
            {{ t('Completed') }}
        </SortableTableHeaderCell>
        <SortableTableHeaderCell column="due_date" v-bind="props" @sort="$emit('sort', $event)">
            {{ t('Due date') }}
        </SortableTableHeaderCell>
        <SortableTableHeaderCell column="spent_time" v-bind="props" @sort="$emit('sort', $event)">
            {{ t('Total Time') }}
        </SortableTableHeaderCell>
        <SortableTableHeaderCell column="progress" v-bind="props" @sort="$emit('sort', $event)">
            {{ t('Progress') }}
        </SortableTableHeaderCell>
        <div class="px-3 py-1.5 text-left text-text-tertiary">{{ t('Timer') }}</div>
        <div class="relative py-1.5 pl-3 pr-4 sm:pr-6 lg:pr-8 3xl:pr-12">
            <span class="sr-only">{{ t('Edit') }}</span>
        </div>
    </TableHeading>
</template>
