import axios from 'axios';
import { computed } from 'vue';
import { useQuery, useQueryClient } from '@tanstack/vue-query';
import { getCurrentOrganizationId } from '@/utils/useUser';
import { canCreateOwnTimeEntries } from '@/utils/permissions';
import { useNotificationsStore } from '@/utils/notification';
import { fetchToken } from '@/utils/session';

export type TaskTimer = {
    id: string;
    task_id: string;
    version: number;
    started_at: number | null;
    elapsed_seconds: number;
};

export type TaskTimerAction = 'start' | 'pause' | 'resume' | 'complete';

export function elapsedTaskSeconds(timer: TaskTimer, now: number) {
    return (
        timer.elapsed_seconds +
        (timer.started_at === null ? 0 : Math.max(0, now - timer.started_at))
    );
}

export function useTaskTimers() {
    const queryClient = useQueryClient();
    const notifications = useNotificationsStore();
    const key = computed(() => ['taskTimers', getCurrentOrganizationId()]);
    const endpoint = (organization: string) => `/api/v1/organizations/${organization}/task-timers`;
    const query = useQuery({
        queryKey: key,
        enabled: () => !!getCurrentOrganizationId() && canCreateOwnTimeEntries(),
        refetchInterval: 15000,
        staleTime: 5000,
        queryFn: async ({ signal }) => {
            const url = endpoint(getCurrentOrganizationId()!);
            const getTimers = () => axios.get<{ data: TaskTimer[] }>(url, { signal });
            try {
                return (await getTimers()).data.data;
            } catch (error) {
                if (!axios.isAxiosError(error) || error.response?.status !== 401) throw error;
                await fetchToken();
                return (await getTimers()).data.data;
            }
        },
    });

    const timers = computed(() => query.data.value ?? []);

    async function change(taskId: string, action: TaskTimerAction, timer?: TaskTimer) {
        const organization = getCurrentOrganizationId();
        if (!organization) return;
        const body = {
            action,
            session_id: timer?.id,
            version: timer?.version ?? 0,
        };

        try {
            const response = await notifications.handleApiRequestNotifications(() =>
                axios.post<{ saved_entries: number }>(`${endpoint(organization)}/${taskId}`, body)
            );
            if (action === 'complete' && response) {
                const count = response.data.saved_entries;
                notifications.addNotification(
                    'success',
                    'Task completed',
                    count === 1
                        ? '1 time entry saved to Calendar.'
                        : `${count} time entries saved to Calendar.`
                );
                for (const name of ['tasks', 'projects', 'timeEntries', 'goals']) {
                    void queryClient.invalidateQueries({ queryKey: [name] });
                }
            }
        } finally {
            await queryClient.invalidateQueries({ queryKey: ['taskTimers', organization] });
        }
    }

    return { timers, change, ready: query.isSuccess };
}
