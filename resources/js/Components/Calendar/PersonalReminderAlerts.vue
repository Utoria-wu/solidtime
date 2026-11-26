<script setup lang="ts">
import axios from 'axios';
import { onMounted, onUnmounted, ref } from 'vue';
import { Bell } from '@lucide/vue';
import { Button } from '@/packages/ui/src/Buttons';
import { personalCalendarChanged, personalCalendarUrl, type Reminder } from './personalCalendar';

const due = ref<Reminder[]>([]);
const dismissing = ref<string>();
const error = ref('');
let timer: number | undefined;
let request: AbortController | undefined;
let active = false;

async function refresh() {
    if (!active || document.hidden) return;
    window.clearTimeout(timer);
    request?.abort();
    const controller = new AbortController();
    request = controller;
    let delay = 30_000;
    try {
        const { data } = await axios.get<{ due: Reminder[]; next_in_ms: number | null }>(
            personalCalendarUrl + '/alerts',
            { signal: controller.signal }
        );
        if (controller.signal.aborted) return;
        due.value = data.due;
        if (data.next_in_ms !== null) delay = Math.min(delay, Math.max(250, data.next_in_ms));
    } catch {
        // Retry while the app is visible, including after a transient connection failure.
    } finally {
        if (!controller.signal.aborted && active) {
            timer = window.setTimeout(refresh, delay);
        }
    }
}

async function dismiss(reminder: Reminder) {
    dismissing.value = reminder.id;
    error.value = '';
    try {
        await axios.post(`${personalCalendarUrl}/reminders/${reminder.id}/dismiss`);
        due.value = due.value.filter((item) => item.id !== reminder.id);
        void refresh();
    } catch {
        error.value = 'Unable to dismiss this reminder. Try again.';
    } finally {
        dismissing.value = undefined;
    }
}

onMounted(() => {
    active = true;
    document.addEventListener('visibilitychange', refresh);
    window.addEventListener('focus', refresh);
    window.addEventListener(personalCalendarChanged, refresh);
    void refresh();
});
onUnmounted(() => {
    active = false;
    window.clearTimeout(timer);
    request?.abort();
    document.removeEventListener('visibilitychange', refresh);
    window.removeEventListener('focus', refresh);
    window.removeEventListener(personalCalendarChanged, refresh);
});
</script>

<template>
    <section
        v-if="due.length"
        aria-label="Personal reminders due"
        class="fixed bottom-20 right-5 z-50 w-[calc(100%-2.5rem)] max-w-sm rounded-lg border border-border bg-card-background p-4 shadow-lg">
        <div class="mb-2 flex items-center gap-2 text-sm font-medium">
            <Bell class="size-4 text-violet-600 dark:text-violet-300" aria-hidden="true" />
            <h2>Reminder due</h2>
        </div>
        <ul class="max-h-60 overflow-auto">
            <li
                v-for="item in due"
                :key="item.id"
                class="flex items-center justify-between gap-3 border-t border-border py-2">
                <span role="status" class="min-w-0 break-words text-sm">{{ item.title }}</span>
                <Button
                    size="xs"
                    variant="outline"
                    :disabled="Boolean(dismissing)"
                    @click="dismiss(item)">
                    Dismiss
                </Button>
            </li>
        </ul>
        <p v-if="error" role="alert" class="text-xs text-destructive">{{ error }}</p>
    </section>
</template>
