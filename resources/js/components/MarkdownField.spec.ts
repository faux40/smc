import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import MarkdownField from './MarkdownField.vue';

describe('MarkdownField', () => {
    it('emits update:modelValue on input', async () => {
        const wrapper = mount(MarkdownField, {
            props: { modelValue: '' },
        });

        await wrapper.get('textarea').setValue('Hello **world**');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([
            'Hello **world**',
        ]);
    });

    it('renders a sanitized Markdown preview when switched to Preview', async () => {
        const wrapper = mount(MarkdownField, {
            props: { modelValue: 'Satisfies **Cal/OSHA**' },
        });

        await wrapper.findAll('[role="tab"]')[1].trigger('click');

        const preview = wrapper.get('[data-testid="markdown-preview"]');
        expect(preview.html()).toContain('<strong>Cal/OSHA</strong>');
    });

    it('shows a placeholder in the preview when empty', async () => {
        const wrapper = mount(MarkdownField, {
            props: { modelValue: '' },
        });

        await wrapper.findAll('[role="tab"]')[1].trigger('click');

        expect(wrapper.get('[data-testid="markdown-preview"]').text()).toContain(
            'Nothing to preview yet.',
        );
    });
});
