import axios from 'axios';
import { computed, type Ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { getCurrentUserId } from '@/utils/useUser';

export interface ReminderFields {
    title: string;
    date: string;
    time: string | null;
}

export interface Reminder extends ReminderFields {
    id: string;
}

export interface Holiday {
    id: number;
    title: string;
    start_date: string;
    end_date: string;
    workday: boolean;
}

export interface PersonalCalendar {
    reminders: Reminder[];
    holidays: Holiday[];
}

export const personalCalendarUrl = '/personal-calendar';
export const personalCalendarChanged = 'solidtime:personal-calendar-changed';

export function usePersonalCalendar(start: Ref<string>, end: Ref<string>) {
    return useQuery({
        queryKey: computed(() => ['personalCalendar', getCurrentUserId(), start.value, end.value]),
        enabled: computed(() => Boolean(start.value && end.value)),
        queryFn: async ({ signal }) => {
            const { data } = await axios.get<PersonalCalendar>(personalCalendarUrl, {
                params: { start: start.value, end: end.value },
                signal,
            });
            return data;
        },
        staleTime: 30_000,
    });
}

export function personalCalendarError(error: unknown) {
    if (axios.isAxiosError<{ message?: string }>(error) && error.response?.status === 422) {
        return error.response.data.message || 'Check the values and try again.';
    }
    return 'The change could not be saved. Please try again.';
}
