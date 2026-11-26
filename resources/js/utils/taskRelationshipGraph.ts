import type { Task } from '@/packages/api/src';
import type { TaskRelationship } from '@/utils/useTaskRelationships';

export function taskRelationshipGraph(
    taskId: string,
    tasks: Task[],
    relationships: TaskRelationship[]
) {
    const taskById = new Map(tasks.map((task) => [task.id, task]));
    const outgoing = new Map<string, TaskRelationship[]>();
    const incoming = new Map<string, TaskRelationship[]>();
    for (const edge of relationships) {
        if (!taskById.has(edge.task_id) || !taskById.has(edge.related_task_id)) continue;
        const children = outgoing.get(edge.related_task_id) ?? [];
        children.push(edge);
        outgoing.set(edge.related_task_id, children);
        const parents = incoming.get(edge.task_id) ?? [];
        parents.push(edge);
        incoming.set(edge.task_id, parents);
    }

    const connected = new Set([taskId]);
    const queue = [taskId];
    const edges: TaskRelationship[] = [];
    for (const id of queue) {
        const children = outgoing.get(id) ?? [];
        edges.push(...children);
        const neighbors = [
            ...children.map((edge) => edge.task_id),
            ...(incoming.get(id) ?? []).map((edge) => edge.related_task_id),
        ];
        for (const neighbor of neighbors) {
            if (connected.has(neighbor)) continue;
            connected.add(neighbor);
            queue.push(neighbor);
        }
    }

    const indegree = new Map(queue.map((id) => [id, incoming.get(id)?.length ?? 0]));
    const ranks = new Map<string, number>();
    const roots = queue.filter((id) => indegree.get(id) === 0);
    for (const id of roots) {
        const rank = ranks.get(id) ?? 0;
        for (const edge of outgoing.get(id) ?? []) {
            const remaining = (indegree.get(edge.task_id) ?? 0) - 1;
            indegree.set(edge.task_id, remaining);
            ranks.set(edge.task_id, Math.max(ranks.get(edge.task_id) ?? 0, rank + 1));
            if (remaining === 0) roots.push(edge.task_id);
        }
    }

    const columns: { task: Task; parents: TaskRelationship[] }[][] = [];
    for (const id of connected) {
        const task = taskById.get(id);
        if (!task) continue;
        const rank = ranks.get(id) ?? 0;
        (columns[rank] ??= []).push({ task, parents: incoming.get(id) ?? [] });
    }
    const order: Record<TaskRelationship['kind'], number> = {
        fixes: 0,
        verifies: 1,
        follows_up_on: 2,
    };
    for (const column of columns) {
        column.sort((a, b) => {
            const priority = (node: typeof a) =>
                Math.min(...node.parents.map((edge) => order[edge.kind]), 3);
            return priority(a) - priority(b) || a.task.name.localeCompare(b.task.name);
        });
    }

    return { columns, edges, incoming, outgoing, taskById };
}
