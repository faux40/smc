import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import CollapsibleSection from '@/components/CollapsibleSection.vue';

function mountSection(props: Record<string, unknown> = {}) {
    return mount(CollapsibleSection, {
        props: { title: 'Forklift', ...props },
        slots: { default: '<p>the fields</p>' },
    });
}

describe('CollapsibleSection', () => {
    it('starts rolled up, showing its title but not its contents', () => {
        const wrapper = mountSection();

        expect(wrapper.text()).toContain('Forklift');
        expect(wrapper.text()).not.toContain('the fields');
    });

    it('rolls open and shut from the header', async () => {
        const wrapper = mountSection();
        const trigger = wrapper.get('[data-testid="section-toggle"]');

        await trigger.trigger('click');
        expect(wrapper.text()).toContain('the fields');

        await trigger.trigger('click');
        expect(wrapper.text()).not.toContain('the fields');
    });

    it('can start open for a section that already has something to say', () => {
        const wrapper = mountSection({ defaultOpen: true });

        expect(wrapper.text()).toContain('the fields');
    });

    it('names what it opens, so the control is not a bare chevron', () => {
        const wrapper = mountSection();
        const trigger = wrapper.get('[data-testid="section-toggle"]');

        expect(trigger.attributes('aria-label')).toContain('Forklift');
    });

    it('renders a summary beside the title while shut', () => {
        // The point of the summary: the roll-up has to be worth leaving
        // closed, which means saying enough to skip it.
        const wrapper = mountSection({ summary: '8h · expires 06/01/27' });

        expect(wrapper.text()).toContain('8h · expires 06/01/27');
    });

    it('reports its state so a caller can label the control', async () => {
        const wrapper = mountSection();

        await wrapper.get('[data-testid="section-toggle"]').trigger('click');

        expect(wrapper.emitted('update:open')?.at(-1)).toEqual([true]);
    });
});
