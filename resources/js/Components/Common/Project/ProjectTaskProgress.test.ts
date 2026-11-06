import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ProjectTaskProgress from './ProjectTaskProgress.vue';

describe('ProjectTaskProgress', () => {
    it('shows completed and total tasks with an accessible progress bar', () => {
        const wrapper = mount(ProjectTaskProgress, {
            props: { tasks: [{ is_done: true }, { is_done: false }, { is_done: false }] },
        });

        expect(wrapper.text()).toContain('1 / 3 Done');
        expect(wrapper.text()).toContain('33%');
        expect(wrapper.attributes('title')).toBe('1 completed, 2 remaining');
        const progress = wrapper.get('[role="progressbar"]');
        expect(progress.attributes('aria-valuenow')).toBe('1');
        expect(progress.attributes('aria-valuemax')).toBe('3');
        expect(progress.attributes('aria-valuetext')).toBe('1 completed, 2 remaining');
    });

    it('updates when tasks are completed, reopened, added or deleted', async () => {
        const wrapper = mount(ProjectTaskProgress, {
            props: { tasks: [{ is_done: false }] },
        });
        expect(wrapper.text()).toContain('0 / 1 Done');
        expect(wrapper.text()).toContain('0%');

        await wrapper.setProps({ tasks: [{ is_done: true }] });
        expect(wrapper.text()).toContain('1 / 1 Done');
        expect(wrapper.text()).toContain('100%');

        await wrapper.setProps({ tasks: [{ is_done: true }, { is_done: false }] });
        expect(wrapper.text()).toContain('1 / 2 Done');
        expect(wrapper.text()).toContain('50%');

        await wrapper.setProps({ tasks: [{ is_done: false }, { is_done: false }] });
        expect(wrapper.text()).toContain('0 / 2 Done');

        await wrapper.setProps({ tasks: [] });
        expect(wrapper.text()).toBe('No tasks');
        expect(wrapper.find('[role="progressbar"]').exists()).toBe(false);
    });

    it('does not report an empty project as complete', () => {
        const wrapper = mount(ProjectTaskProgress, { props: { tasks: [] } });
        expect(wrapper.text()).toBe('No tasks');
        expect(wrapper.text()).not.toContain('%');
    });

    it('does not show an empty task count while tasks are loading', async () => {
        const wrapper = mount(ProjectTaskProgress, { props: { tasks: [], isLoading: true } });
        expect(wrapper.text()).toBe('Loading tasks…');

        await wrapper.setProps({ tasks: [{ is_done: true }], isLoading: false });
        expect(wrapper.text()).toContain('1 / 1 Done');
    });

    it('distinguishes a failed request from a project without tasks', () => {
        const wrapper = mount(ProjectTaskProgress, { props: { tasks: [], isError: true } });
        expect(wrapper.text()).toBe('Tasks unavailable');
        expect(wrapper.find('[role="progressbar"]').exists()).toBe(false);
    });
});
