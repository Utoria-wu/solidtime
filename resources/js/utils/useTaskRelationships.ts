import { computed } from 'vue';
import { useQuery, useQueryClient } from '@tanstack/vue-query';
import { fetchAllPages } from '@/utils/fetchAllPages';
import { useNotificationsStore } from '@/utils/notification';
import { getCurrentOrganizationId } from '@/utils/useUser';
import { requestWithSession } from '@/utils/requestWithSession';

export const taskRelationshipKinds = {
    fixes: { label: 'Fixes', edgeLabel: 'Led to fix', nodeLabel: 'Fix', color: '#5483a2' },
    verifies: {
        label: 'Verifies',
        edgeLabel: 'Verified by',
        nodeLabel: 'Verification',
        color: '#508471',
    },
    follows_up_on: {
        label: 'Follows up on',
        edgeLabel: 'Follow-up',
        nodeLabel: 'Follow-up',
        color: '#8271aa',
    },
} as const;

export type TaskRelationshipKind = keyof typeof taskRelationshipKinds;
export type TaskRelationship = {
    id: string;
    task_id: string;
    related_task_id: string;
    kind: TaskRelationshipKind;
};

export function useTaskRelationships(projectId: string) {
    const client = useQueryClient();
    const notifications = useNotificationsStore();
    const key = computed(
        () => ['taskRelationships', getCurrentOrganizationId(), projectId] as const
    );
    const endpoint = (organization: string) =>
        `/api/v1/organizations/${organization}/projects/${projectId}/task-relationships`;
    const query = useQuery({
        queryKey: key,
        enabled: () => Boolean(getCurrentOrganizationId() && projectId),
        staleTime: 30000,
        queryFn: ({ queryKey: [, organization], signal }) => {
            if (!organization) throw new Error('No organization');
            return fetchAllPages((page) =>
                requestWithSession<{
                    data: TaskRelationship[];
                    meta: { per_page: number; last_page: number };
                }>({
                    url: endpoint(organization),
                    params: { page },
                    signal,
                })
            );
        },
    });

    async function link(body: Omit<TaskRelationship, 'id'>) {
        const queryKey = key.value;
        const organization = queryKey[1];
        if (!organization) return false;
        const result = await notifications.handleApiRequestNotifications(
            () =>
                requestWithSession<{ data: TaskRelationship }>({
                    url: endpoint(organization),
                    method: 'post',
                    data: body,
                }),
            'Task linked',
            'Could not link tasks'
        );
        if (!result) return false;
        await client.cancelQueries({ queryKey });
        client.setQueryData<TaskRelationship[]>(queryKey, (previous) => [
            ...(previous ?? []).filter((link) => link.id !== result.data.id),
            result.data,
        ]);
        void client.invalidateQueries({ queryKey });
        return true;
    }

    async function unlink(relationshipId: string) {
        const queryKey = key.value;
        const organization = queryKey[1];
        if (!organization) return false;
        const result = await notifications.handleApiRequestNotifications(
            async () => {
                await requestWithSession({
                    url: `${endpoint(organization)}/${relationshipId}`,
                    method: 'delete',
                });
                return true;
            },
            'Task link removed',
            'Could not remove task link'
        );
        if (!result) return false;
        await client.cancelQueries({ queryKey });
        client.setQueryData<TaskRelationship[]>(queryKey, (previous) =>
            (previous ?? []).filter((link) => link.id !== relationshipId)
        );
        void client.invalidateQueries({ queryKey });
        return true;
    }

    return { ...query, relationships: computed(() => query.data.value ?? []), link, unlink };
}
