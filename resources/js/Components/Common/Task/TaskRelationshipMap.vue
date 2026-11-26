<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { useResizeObserver } from '@vueuse/core';
import type { Task } from '@/packages/api/src';
import { taskRelationshipGraph } from '@/utils/taskRelationshipGraph';
import { taskRelationshipKinds, type TaskRelationship } from '@/utils/useTaskRelationships';

const props = defineProps<{ taskId: string; tasks: Task[]; relationships: TaskRelationship[] }>();
const emit = defineEmits<{ openActivity: [task: Task] }>();
const board = ref<HTMLElement | null>(null);
const hovered = ref<string | null>(null);
const focused = ref<string | null>(null);
const selected = computed(() => hovered.value ?? focused.value);
const graph = computed(() => taskRelationshipGraph(props.taskId, props.tasks, props.relationships));
const geometry = ref<{
    width: number;
    height: number;
    edges: { edge: TaskRelationship; path: string; arrow: string; x: number; y: number }[];
}>({ width: 0, height: 0, edges: [] });

const traced = computed(() => {
    const exactEdges = new Set<string>();
    const nodes = new Set<string>();
    if (selected.value) {
        const queue = [selected.value];
        for (const id of queue) {
            if (nodes.has(id)) continue;
            nodes.add(id);
            for (const edge of graph.value.incoming.get(id) ?? []) {
                exactEdges.add(edge.id);
                queue.push(edge.related_task_id);
            }
        }
    }
    return { nodes, edges: exactEdges };
});

function measure() {
    const element = board.value;
    if (!element) return;
    const rect = element.getBoundingClientRect();
    const nodes = new Map(
        [...element.querySelectorAll<HTMLElement>('[data-relationship-task]')].map((node) => [
            node.dataset.relationshipTask,
            node.getBoundingClientRect(),
        ])
    );
    geometry.value = {
        width: rect.width,
        height: rect.height,
        edges: graph.value.edges.flatMap((edge) => {
            const from = nodes.get(edge.related_task_id);
            const to = nodes.get(edge.task_id);
            if (!from || !to || from.right >= to.left) return [];
            const left = from.right - rect.left;
            const right = to.left - rect.left - 5;
            const y1 = from.top - rect.top + from.height / 2;
            const y2 = to.top - rect.top + to.height / 2;
            const middle = (left + right) / 2;
            return [
                {
                    edge,
                    path: `M ${left} ${y1} C ${middle} ${y1}, ${middle} ${y2}, ${right} ${y2}`,
                    arrow: `M ${right - 4} ${y2 - 3} L ${right} ${y2} L ${right - 4} ${y2 + 3}`,
                    x: middle,
                    y: (y1 + y2) / 2 - 10,
                },
            ];
        }),
    };
}

useResizeObserver(board, measure);
watch(graph, async () => {
    await nextTick();
    measure();
});

function nodeRole(taskId: string, parents: TaskRelationship[]) {
    const kinds = new Set(parents.map((edge) => edge.kind));
    const kind = kinds.size === 1 ? parents[0]?.kind : undefined;
    if (kind) return taskRelationshipKinds[kind];
    if (
        !parents.length &&
        graph.value.outgoing.get(taskId)?.some((edge) => edge.kind === 'fixes')
    ) {
        return { nodeLabel: 'Issue', color: '#ae8150' };
    }
    return { nodeLabel: 'Task', color: '#9b855e' };
}

function relationshipText(edge: TaskRelationship) {
    return `${taskRelationshipKinds[edge.kind].label} ${graph.value.taskById.get(edge.related_task_id)?.name ?? ''}`;
}
</script>

<template>
    <div
        v-if="graph.edges.length"
        class="relationship-scroll"
        data-testid="task-relationship-map"
        role="group"
        aria-label="Task relationships">
        <div ref="board" class="relationship-board" :style="{ '--columns': graph.columns.length }">
            <svg
                class="relationship-edges"
                :width="geometry.width"
                :height="geometry.height"
                aria-hidden="true"
                fill="none">
                <g
                    v-for="item in geometry.edges"
                    :key="item.edge.id"
                    :class="{
                        faded: traced.edges.size && !traced.edges.has(item.edge.id),
                        traced: traced.edges.has(item.edge.id),
                    }"
                    :stroke="taskRelationshipKinds[item.edge.kind].color"
                    stroke-linecap="round"
                    stroke-linejoin="round">
                    <path
                        :d="item.path"
                        :stroke-dasharray="
                            item.edge.kind === 'follows_up_on' ? '4 3' : undefined
                        " />
                    <path :d="item.arrow" />
                </g>
            </svg>
            <span
                v-for="item in geometry.edges"
                :key="item.edge.id"
                class="relationship-label bg-default-background text-text-secondary"
                :style="{ left: `${item.x}px`, top: `${item.y}px` }"
                :title="relationshipText(item.edge)"
                aria-hidden="true"
                >{{ taskRelationshipKinds[item.edge.kind].edgeLabel }}</span
            >

            <div v-for="(column, index) in graph.columns" :key="index" class="relationship-column">
                <button
                    v-for="node in column"
                    :key="node.task.id"
                    type="button"
                    :data-relationship-task="node.task.id"
                    :aria-label="node.task.name"
                    :aria-description="node.parents.map(relationshipText).join('. ') || undefined"
                    class="relationship-node bg-default-background text-left hover:bg-tertiary focus-visible:bg-tertiary"
                    :style="{ '--node-color': nodeRole(node.task.id, node.parents).color }"
                    :class="{ 'relationship-trace': traced.nodes.has(node.task.id) }"
                    @pointerenter="hovered = node.task.id"
                    @pointerleave="hovered = null"
                    @focus="focused = node.task.id"
                    @blur="focused = null"
                    @click="emit('openActivity', node.task)">
                    <span class="flex items-center gap-1.5 text-xs text-text-secondary"
                        ><span class="relationship-dot" aria-hidden="true"></span
                        >{{ nodeRole(node.task.id, node.parents).nodeLabel }}</span
                    >
                    <span class="block break-words text-sm font-medium text-text-primary">{{
                        node.task.name
                    }}</span>
                    <span class="block text-xs text-text-secondary">{{
                        node.task.is_canceled ? 'Canceled' : node.task.is_done ? 'Done' : 'Active'
                    }}</span>
                    <span
                        v-for="edge in node.parents"
                        :key="edge.id"
                        class="relationship-compact mt-0.5 break-words text-xs text-text-secondary"
                        >{{ relationshipText(edge) }}</span
                    >
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.relationship-scroll {
    container-type: inline-size;
    overflow-x: auto;
}
.relationship-board {
    position: relative;
    isolation: isolate;
    display: grid;
    grid-template-columns: repeat(var(--columns), minmax(9rem, 1fr));
    gap: 4.75rem;
    min-width: calc(var(--columns) * 13.75rem - 4.75rem);
    max-width: 68rem;
    padding-block: 0.2rem;
}
.relationship-column {
    display: flex;
    min-width: 0;
    flex-direction: column;
    gap: 0.55rem;
}
.relationship-edges {
    position: absolute;
    inset: 0;
    pointer-events: none;
}
.relationship-edges g {
    stroke-width: 1.35px;
}
.relationship-edges .faded {
    opacity: 0.4;
}
.relationship-edges .traced {
    stroke-width: 1.9px;
}
.relationship-label {
    position: absolute;
    transform: translate(-50%, -50%);
    padding-inline: 0.18rem;
    font-size: 0.67rem;
    white-space: nowrap;
    pointer-events: none;
}
.relationship-node {
    position: relative;
    min-width: 0;
    min-height: 4.75rem;
    padding: 0.4rem 0.5rem;
    border-inline-start: 1px solid var(--theme-color-default-background-separator);
    border-radius: 0.25rem;
}
.relationship-node.relationship-trace {
    border-inline-start-color: var(--node-color);
}
.relationship-dot {
    width: 0.36rem;
    height: 0.36rem;
    border-radius: 50%;
    background: var(--node-color);
    flex: none;
}
.relationship-compact {
    display: none;
}

@container (max-width: 38rem) {
    .relationship-board {
        grid-template-columns: minmax(0, 1fr);
        gap: 0.35rem;
        min-width: 0;
    }
    .relationship-column {
        display: contents;
    }
    .relationship-node {
        min-height: 0;
    }
    .relationship-edges,
    .relationship-label {
        display: none;
    }
    .relationship-compact {
        display: block;
    }
}
</style>
