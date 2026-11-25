<script setup lang="ts">
import EstimatedTimeInput from '@/packages/ui/src/Input/EstimatedTimeInput.vue';
import { ClockIcon } from '@heroicons/vue/20/solid';
import { Field, FieldLabel } from './field';

const model = defineModel<number | null>();
const emit = defineEmits(['submit']);
</script>

<template>
    <Field>
        <FieldLabel for="time_estimated" :icon="ClockIcon">Time Estimated</FieldLabel>
        <EstimatedTimeInput
            id="time_estimated"
            v-model="model"
            @submit="emit('submit')"></EstimatedTimeInput>
    </Field>
</template>

<style scoped></style>
