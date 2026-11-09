import { shallowMount, flushPromises } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Task, TimeEntry } from '@/packages/api/src';
import TimeEntryEditModal from './TimeEntryEditModal.vue';

const mocks = vi.hoisted(() => ({ updateTask: vi.fn(), allowed: true }));
vi.mock('@/utils/useTasks', () => ({ useTasksStore: () => mocks }));
vi.mock('@/utils/permissions', () => ({ canUpdateTasks: () => mocks.allowed }));

const task = { id: 'task-1', project_id: 'project-1', name: 'First task', is_done: false } as Task;
const entry = {
    id: 'entry-1',
    project_id: task.project_id,
    task_id: task.id,
    description: 'Work',
    type: 'work',
    tags: [],
    billable: false,
    start: '2026-11-09T01:00:00Z',
    end: '2026-11-09T02:00:00Z',
} as unknown as TimeEntry;

function setup(timeEntry = entry, tasks = [task]) {
    const save = vi.fn().mockResolvedValue(undefined);
    const wrapper = shallowMount(TimeEntryEditModal, {
        props: {
            show: true,
            timeEntry,
            tasks,
            updateTimeEntry: save,
            deleteTimeEntry: vi.fn(),
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
        editableTimeEntry: TimeEntry;
        taskStatus: string;
    };
    const choose = async (status: string) => {
        const select = wrapper
            .findAllComponents({ name: 'Select' })
            .find((component) => component.find('[aria-label="Task status"]').exists());
        expect(select).toBeDefined();
        select!.vm.$emit('update:modelValue', status);
        await flushPromises();
    };
    return { wrapper, save, state, choose };
}

beforeEach(() => {
    mocks.allowed = true;
    mocks.updateTask.mockReset().mockResolvedValue(undefined);
});

describe('time entry task status', () => {
    it('shows the current status and leaves unchanged tasks alone', async () => {
        const { wrapper, state, save } = setup(entry, [{ ...task, is_done: true }]);
        await flushPromises();
        expect(state.taskStatus).toBe('done');
        await state.submit();
        expect(save).toHaveBeenCalledTimes(1);
        expect(mocks.updateTask).not.toHaveBeenCalled();
        expect(wrapper.emitted('update:show')?.at(-1)).toEqual([false]);
    });

    it.each([
        ['done', false, true],
        ['active', true, false],
    ] as const)(
        'saves %s only after the time entry succeeds',
        async (status, initial, expected) => {
            const { state, save, choose } = setup(entry, [{ ...task, is_done: initial }]);
            await flushPromises();
            let resolveSave!: () => void;
            save.mockImplementationOnce(
                () =>
                    new Promise<void>((resolve) => {
                        resolveSave = resolve;
                    })
            );
            await choose(status);
            const pending = state.submit();
            expect(mocks.updateTask).not.toHaveBeenCalled();
            await state.submit();
            expect(save).toHaveBeenCalledTimes(1);
            resolveSave();
            await pending;
            expect(mocks.updateTask).toHaveBeenCalledWith(task.id, {
                name: task.name,
                is_done: expected,
            });
        }
    );

    it('does not change the task or close after a time-entry failure', async () => {
        const { wrapper, state, save, choose } = setup();
        await flushPromises();
        await choose('done');
        save.mockRejectedValueOnce(new Error('Time entry rejected'));
        await expect(state.submit()).rejects.toThrow('Time entry rejected');
        expect(mocks.updateTask).not.toHaveBeenCalled();
        expect(wrapper.emitted('update:show')).toBeUndefined();
    });

    it('retains the chosen status for retry after task failure and entry refresh', async () => {
        const { wrapper, state, save, choose } = setup();
        await flushPromises();
        await choose('done');
        save.mockImplementation(async () => {
            await wrapper.setProps({ timeEntry: { ...entry } });
        });
        mocks.updateTask.mockRejectedValueOnce(new Error('Task rejected'));
        await expect(state.submit()).rejects.toThrow('Task rejected');
        expect(wrapper.emitted('update:show')).toBeUndefined();
        expect(state.taskStatus).toBe('done');
        await state.submit();
        expect(mocks.updateTask).toHaveBeenCalledTimes(2);
        expect(wrapper.emitted('update:show')?.at(-1)).toEqual([false]);
    });

    it('discards pending status when switching tasks', async () => {
        const second = { ...task, id: 'task-2', name: 'Second task' };
        const { state, choose } = setup(entry, [task, second]);
        await flushPromises();
        await choose('done');
        state.editableTimeEntry.task_id = second.id;
        await flushPromises();
        expect(state.taskStatus).toBe('active');
        await state.submit();
        expect(mocks.updateTask).not.toHaveBeenCalled();
    });

    it('discards unsaved edits when the dialog is reopened', async () => {
        const { wrapper, state, choose, save } = setup();
        await flushPromises();
        await choose('done');
        await wrapper.setProps({ show: false });
        await wrapper.setProps({ show: true });
        expect(state.taskStatus).toBe('active');
        expect(save).not.toHaveBeenCalled();
        expect(mocks.updateTask).not.toHaveBeenCalled();
    });

    it.each(['no task', 'no permission', 'break'])('hides task status for %s', async (reason) => {
        mocks.allowed = reason !== 'no permission';
        const { wrapper, state } = setup({
            ...entry,
            task_id: reason === 'no task' ? null : task.id,
            type: reason === 'break' ? 'break' : 'work',
        });
        await flushPromises();
        expect(wrapper.find('[aria-label="Task status"]').exists()).toBe(false);
        await state.submit();
        expect(mocks.updateTask).not.toHaveBeenCalled();
    });
});
