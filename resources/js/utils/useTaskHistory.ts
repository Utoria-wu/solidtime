import { computed } from 'vue';
import { useQuery, useQueryClient } from '@tanstack/vue-query';
import { getCurrentOrganizationId } from '@/utils/useUser';
import { useNotificationsStore } from '@/utils/notification';
import { requestWithSession as request } from '@/utils/requestWithSession';

export type TaskCategory = {
    id: string;
    name: string;
    color: string;
    sort_order: number;
    is_archived: boolean;
};
export const historyKinds = {
    progress: { label: 'Progress', color: '#238c9c' },
    problem: { label: 'Problem', color: '#c97757' },
    investigation: { label: 'Investigation', color: '#8271bd' },
    attempt: { label: 'Attempt', color: '#b28b40' },
    finding: { label: 'Finding', color: '#488f88' },
    action: { label: 'Action', color: '#5389b0' },
    verification: { label: 'Verification', color: '#4e936d' },
} as const;
export type HistoryKind = keyof typeof historyKinds;
export type HistoryEntry = {
    id: string;
    task_id: string;
    kind: HistoryKind;
    title: string;
    markdown_notes: string | null;
    outcome: 'unsuccessful' | 'resolved' | 'verified' | null;
    occurred_at: string;
    issue_id: string | null;
    parent_id: string | null;
    time_entry_id: string | null;
    version: number;
    author_name: string | null;
    source: { id: string; description: string | null; start: string; end: string | null } | null;
};
export type HistoryBody = Omit<HistoryEntry, 'id' | 'author_name' | 'source'>;
const base = () => `/api/v1/organizations/${getCurrentOrganizationId()}`;

export function useTaskCategories() {
    const client = useQueryClient();
    const notifications = useNotificationsStore();
    const query = useQuery({
        queryKey: computed(() => ['taskCategories', getCurrentOrganizationId()]),
        enabled: () => !!getCurrentOrganizationId(),
        staleTime: 30000,
        queryFn: ({ signal }) =>
            request<{ data: TaskCategory[] }>({ url: `${base()}/task-categories`, signal }),
    });
    async function save(body: Omit<TaskCategory, 'id'>, id?: string) {
        const result = await notifications.handleApiRequestNotifications(
            () =>
                request<{ data: TaskCategory }>({
                    url: `${base()}/task-categories${id ? '/' + id : ''}`,
                    method: id ? 'put' : 'post',
                    data: body,
                }),
            'Category saved',
            'Could not save category'
        );
        if (!result) return false;
        const queryKey = ['taskCategories', getCurrentOrganizationId()];
        await client.cancelQueries({ queryKey });
        client.setQueryData<{ data: TaskCategory[] }>(queryKey, (previous) => ({
            data: [
                ...(previous?.data ?? []).filter((item) => item.id !== result.data.id),
                result.data,
            ].sort(
                (left, right) =>
                    left.sort_order - right.sort_order || left.name.localeCompare(right.name)
            ),
        }));
        void client.invalidateQueries({ queryKey });
        return result.data;
    }
    return { ...query, categories: computed(() => query.data.value?.data ?? []), save };
}

export function useTaskHistory(projectId: string) {
    const client = useQueryClient();
    const notifications = useNotificationsStore();
    const key = computed(() => ['taskHistory', getCurrentOrganizationId(), projectId]);
    const endpoint = () => `${base()}/projects/${projectId}/history`;
    const query = useQuery({
        queryKey: key,
        enabled: () => !!getCurrentOrganizationId(),
        staleTime: 30000,
        queryFn: async ({ signal }) => {
            const entries: HistoryEntry[] = [];
            for (let page = 1; ; page++) {
                const response = await request<{
                    data: HistoryEntry[];
                    meta: { last_page: number };
                }>({ url: endpoint(), params: { page }, signal });
                entries.push(...response.data);
                if (page >= response.meta.last_page) return entries;
            }
        },
    });
    async function save(body: HistoryBody, id?: string) {
        const result = await notifications.handleApiRequestNotifications(
            () =>
                request<{ data: HistoryEntry }>({
                    url: `${endpoint()}${id ? '/' + id : ''}`,
                    method: id ? 'put' : 'post',
                    data: body,
                }),
            'History record saved',
            'Could not save history record'
        );
        if (result) {
            await client.cancelQueries({ queryKey: key.value });
            client.setQueryData<HistoryEntry[]>(key.value, (previous) =>
                [
                    ...(previous ?? []).filter((entry) => entry.id !== result.data.id),
                    result.data,
                ].sort(
                    (left, right) =>
                        Date.parse(left.occurred_at) - Date.parse(right.occurred_at) ||
                        left.id.localeCompare(right.id)
                )
            );
        }
        void client.invalidateQueries({ queryKey: key.value });
        return result?.data;
    }
    async function remove(entry: HistoryEntry) {
        // A defined result also represents a successful 204 response.
        const result = await notifications.handleApiRequestNotifications(
            async () => {
                await request({
                    url: `${endpoint()}/${entry.id}`,
                    method: 'delete',
                    data: { version: entry.version },
                });
                return true;
            },
            'History record deleted',
            'Could not delete history record'
        );
        if (result === true) {
            await client.cancelQueries({ queryKey: key.value });
            client.setQueryData<HistoryEntry[]>(key.value, (previous) =>
                (previous ?? []).filter((item) => item.id !== entry.id)
            );
        }
        void client.invalidateQueries({ queryKey: key.value });
        return result === true;
    }
    return { ...query, entries: computed(() => query.data.value ?? []), save, remove };
}
