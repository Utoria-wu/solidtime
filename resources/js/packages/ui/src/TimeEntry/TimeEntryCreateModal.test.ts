import { shallowMount, flushPromises } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { CreateTimeEntryBody, Task } from '@/packages/api/src';
import TimeEntryCreateModal from './TimeEntryCreateModal.vue';

const mocks = vi.hoisted(() => ({ updateTask: vi.fn(), allowed: true }));
vi.mock('@/utils/useTasks', () => ({ useTasksStore: () => mocks }));
vi.mock('@/utils/permissions', () => ({ canUpdateTasks: () => mocks.allowed }));
const first = { id: 'task-1', project_id: 'project-1', name: 'First task', is_done: false } as Task;
const second = {
    id: 'task-2',
    project_id: 'project-2',
    name: 'Second task',
    is_done: true,
} as Task;

async function setup() {
    const create = vi.fn().mockResolvedValue(undefined);
    const wrapper = shallowMount(TimeEntryCreateModal, {
        props: {
            show: false,
            tasks: [first, second],
            createTimeEntry: create,
            createClient: vi.fn(),
            createProject: vi.fn(),
            createTag: vi.fn(),
            tags: [],
            projects: [],
            clients: [],
            currency: 'USD',
            organizationBillableRate: null,
            canCreateProject: false,
            enableEstimatedTime: false,
            start: '2026-11-09T01:00:00Z',
            end: '2026-11-09T02:00:00Z',
        },
        global: {
            renderStubDefaultSlot: true,
            stubs: {
                DialogModal: { template: '<div><slot name="content"/><slot name="footer"/></div>' },
                TextInput: { template: '<input />', methods: { focus() {} } },
            },
        },
    });
    const state = wrapper.vm as unknown as {
        submit: () => Promise<void>;
        timeEntry: Omit<CreateTimeEntryBody, 'member_id'>;
        taskStatus: string;
    };
    const selectTask = async (task: Task) => {
        state.timeEntry.project_id = task.project_id;
        state.timeEntry.task_id = task.id;
        await flushPromises();
    };
    const choose = async (status: string) => {
        const select = wrapper
            .findAllComponents({ name: 'Select' })
            .find((component) => component.find('[aria-label="Task status"]').exists());
        select!.vm.$emit('update:modelValue', status);
        await flushPromises();
    };
    await wrapper.setProps({ show: true });
    await flushPromises();
    return { wrapper, create, state, selectTask, choose };
}

beforeEach(() => {
    mocks.allowed = true;
    mocks.updateTask.mockReset().mockResolvedValue(undefined);
});

describe('manual time entry task status', () => {
    it.each(['cancel', 'save'])(
        'shows status on the second calendar range after %s',
        async (action) => {
            const { wrapper, state, selectTask, create } = await setup();
            await selectTask(first);
            expect(wrapper.find('[aria-label="Task status"]').exists()).toBe(true);
            expect(state.taskStatus).toBe('active');
            if (action === 'save') await state.submit();
            await wrapper.setProps({ show: false });
            await wrapper.setProps({
                show: true,
                start: '2026-11-09T03:00:00Z',
                end: '2026-11-09T04:00:00Z',
            });
            expect(state.timeEntry.task_id).toBeNull();
            await selectTask(second);
            expect(wrapper.find('[aria-label="Task status"]').exists()).toBe(true);
            expect(state.taskStatus).toBe('done');
            expect(state.timeEntry.start).toBe('2026-11-09T03:00:00Z');
            expect(state.timeEntry.end).toBe('2026-11-09T04:00:00Z');
            await state.submit();
            expect(create).toHaveBeenCalledTimes(action === 'save' ? 2 : 1);
        }
    );

    it('creates the entry before saving the chosen task status', async () => {
        const { create, state, selectTask, choose } = await setup();
        await selectTask(first);
        await choose('done');
        await state.submit();
        expect(create).toHaveBeenCalledTimes(1);
        expect(mocks.updateTask).toHaveBeenCalledWith(first.id, {
            name: first.name,
            is_done: true,
        });
        expect(create.mock.invocationCallOrder[0]).toBeLessThan(
            mocks.updateTask.mock.invocationCallOrder[0]!
        );
    });

    it('retries a failed task update without creating a duplicate entry', async () => {
        const { wrapper, create, state, selectTask, choose } = await setup();
        await selectTask(first);
        await choose('done');
        mocks.updateTask.mockRejectedValueOnce(new Error('Task failed'));
        await expect(state.submit()).rejects.toThrow('Task failed');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('Time entry saved');
        expect(wrapper.emitted('update:show')).toBeUndefined();
        await state.submit();
        expect(create).toHaveBeenCalledTimes(1);
        expect(mocks.updateTask).toHaveBeenCalledTimes(2);
        expect(wrapper.emitted('update:show')?.at(-1)).toEqual([false]);
    });

    it('does not change the task when creating the time entry fails', async () => {
        const { create, state, selectTask, choose } = await setup();
        await selectTask(first);
        await choose('done');
        create.mockRejectedValueOnce(new Error('Create failed'));
        await expect(state.submit()).rejects.toThrow('Create failed');
        expect(mocks.updateTask).not.toHaveBeenCalled();
    });

    it('does not update unchanged status or save canceled drafts', async () => {
        const { wrapper, state, selectTask, choose, create } = await setup();
        await selectTask(first);
        await choose('done');
        await wrapper.setProps({ show: false });
        await wrapper.setProps({ show: true });
        expect(create).not.toHaveBeenCalled();
        await selectTask(first);
        expect(state.taskStatus).toBe('active');
        await state.submit();
        expect(mocks.updateTask).not.toHaveBeenCalled();
    });

    it('hides status without task-update permission', async () => {
        mocks.allowed = false;
        const { wrapper, selectTask } = await setup();
        await selectTask(first);
        expect(wrapper.find('[aria-label="Task status"]').exists()).toBe(false);
    });
});
