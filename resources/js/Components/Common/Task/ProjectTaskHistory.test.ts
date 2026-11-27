import { shallowMount } from '@vue/test-utils';
import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query';
import { ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Task, TimeEntry } from '@/packages/api/src';
import ProjectTaskHistory from './ProjectTaskHistory.vue';

const { getTimeEntries } = vi.hoisted(() => ({ getTimeEntries: vi.fn() }));

vi.mock('@/packages/api/src', () => ({ api: { getTimeEntries } }));
vi.mock('@/utils/useUser', () => ({
    getCurrentOrganizationId: () => 'organization-1',
    getCurrentMembershipId: () => 'member-1',
}));
vi.mock('@/utils/permissions', () => ({
    canCreateTags: () => false,
    canUpdateTags: () => false,
    canUpdateTasks: () => false,
    canViewAllTimeEntries: () => false,
}));
vi.mock('@/utils/useTaskHistory', () => ({
    historyKinds: {},
    useTaskCategories: () => ({ categories: ref([]), isError: ref(false) }),
    useTaskHistory: () => ({ entries: ref([]), isPending: ref(false), isError: ref(false) }),
}));
vi.mock('@/utils/useTaskRelationships', () => ({
    useTaskRelationships: () => ({ relationships: ref([]), isError: ref(false) }),
}));

function calendarEntry(overrides: Partial<TimeEntry>): TimeEntry {
    return {
        id: 'calendar-entry',
        start: '2026-11-18T10:00:00Z',
        end: '2026-11-18T11:00:00Z',
        duration: 3600,
        description: '',
        markdown_notes: null,
        project_id: 'project-1',
        task_id: null,
        organization_id: 'organization-1',
        user_id: 'user-1',
        tags: [],
        billable: false,
        type: 'work',
        ...overrides,
    };
}

function mountHistory(tasks: Task[] = []) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const wrapper = shallowMount(ProjectTaskHistory, {
        props: { projectId: 'project-1', tasks },
        global: {
            plugins: [[VueQueryPlugin, { queryClient: client }]],
            stubs: {
                ProjectHistoryTimeline: false,
                Popover: { template: '<div><slot /></div>' },
                PopoverTrigger: { template: '<div><slot /></div>' },
                PopoverContent: { template: '<div><slot /></div>' },
            },
        },
    });
    return {
        wrapper,
        close: () => {
            wrapper.unmount();
            client.clear();
        },
    };
}

describe('ProjectTaskHistory', () => {
    let history: ReturnType<typeof mountHistory> | undefined;

    beforeEach(() => getTimeEntries.mockReset());
    afterEach(() => history?.close());

    it('shows and links paginated project Calendar entries even when there are no tasks', async () => {
        getTimeEntries
            .mockResolvedValueOnce({
                data: [calendarEntry({ description: 'Practice session' })],
                meta: { total: 2 },
            })
            .mockResolvedValueOnce({
                data: [
                    calendarEntry({
                        id: 'older-entry',
                        start: '2026-11-17T10:00:00Z',
                        end: '2026-11-17T11:00:00Z',
                        description: 'Review session',
                        markdown_notes: '**Worked on technique**',
                    }),
                ],
                meta: { total: 2 },
            });

        history = mountHistory();
        const { wrapper } = history;
        await vi.waitFor(() => expect(wrapper.findAll('article')).toHaveLength(2));

        expect(wrapper.findAll('article').map((record) => record.attributes('id'))).toEqual([
            'history-calendar-entry',
            'history-older-entry',
        ]);
        const older = wrapper.get('#history-older-entry');
        expect(older.text()).toContain('Review session');
        expect(older.get('strong').text()).toBe('Worked on technique');
        expect(older.get('a').attributes('href')).toBe(
            '/calendar?date=2026-11-17&entry=older-entry'
        );
        expect(getTimeEntries).toHaveBeenLastCalledWith(
            expect.objectContaining({
                params: { organization: 'organization-1' },
                queries: {
                    active: 'false',
                    project_ids: ['project-1'],
                    member_id: 'member-1',
                    offset: 1,
                },
            })
        );
    });

    it('keeps task history and project-only Calendar activity individually selectable', async () => {
        getTimeEntries.mockResolvedValue({
            data: [
                calendarEntry({
                    id: 'linked-entry',
                    task_id: 'task-1',
                    description: 'Linked session',
                }),
                calendarEntry({ id: 'project-entry', description: 'Project-only session' }),
                calendarEntry({ id: 'running-entry', end: null, description: 'Running session' }),
            ],
            meta: { total: 3 },
        });
        const task: Task = {
            id: 'task-1',
            name: 'Project task',
            is_done: true,
            project_id: 'project-1',
            created_at: '2026-11-17T10:00:00Z',
            done_at: '2026-11-18T12:00:00Z',
            updated_at: '2026-11-18T12:00:00Z',
            spent_time: 3600,
            estimated_time: null,
            completion_notes: 'Task complete',
        };
        history = mountHistory([task]);
        const { wrapper } = history;
        await vi.waitFor(() => expect(wrapper.findAll('article')).toHaveLength(4));
        expect(wrapper.get('#history-linked-entry').text()).toContain('Project task');
        expect(wrapper.get('#history-completed-task-1').text()).toContain('Task complete');
        expect(wrapper.find('#history-running-entry').exists()).toBe(false);

        await wrapper.get('select[aria-label="Activity type"]').setValue('unlinked');
        expect(wrapper.findAll('article').map((record) => record.attributes('id'))).toEqual([
            'history-project-entry',
        ]);
        const historyViews = wrapper.get('[aria-label="History view"]');
        await historyViews
            .findAll('button')
            .find((button) => button.text() === 'Tasks')!
            .trigger('click');
        await wrapper
            .findAll('li button')
            .find((button) => button.text().startsWith('Project task'))!
            .trigger('click');
        expect(wrapper.findAll('article')).toHaveLength(3);
        expect(wrapper.find('#history-project-entry').exists()).toBe(false);
        await historyViews
            .findAll('button')
            .find((button) => button.text() === 'Timeline')!
            .trigger('click');
        await wrapper.get('select[aria-label="Activity type"]').setValue('all');
        expect(wrapper.findAll('article')).toHaveLength(4);
    });
});
