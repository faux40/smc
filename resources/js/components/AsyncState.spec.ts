import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { h } from 'vue';
import AsyncState from '@/components/AsyncState.vue';

const content = () => h('div', { class: 'loaded' }, 'rows here');

describe('AsyncState', () => {
    it('shows the spinner while loading and not the content', () => {
        const wrapper = mount(AsyncState, {
            props: { loading: true },
            slots: { default: content },
        });

        expect(wrapper.find('[role="status"]').exists()).toBe(true);
        expect(wrapper.find('.loaded').exists()).toBe(false);
    });

    it('shows the error over content/empty when present', () => {
        const wrapper = mount(AsyncState, {
            props: { loading: false, error: 'Boom', empty: true },
            slots: { default: content },
        });

        expect(wrapper.text()).toContain('Boom');
        expect(wrapper.find('.loaded').exists()).toBe(false);
    });

    it('renders the empty slot when empty and not loading/error', () => {
        const wrapper = mount(AsyncState, {
            props: { loading: false, empty: true },
            slots: {
                default: content,
                empty: () => h('div', { class: 'empty' }, 'no rows'),
            },
        });

        expect(wrapper.find('.empty').exists()).toBe(true);
        expect(wrapper.find('.loaded').exists()).toBe(false);
    });

    it('renders the default slot once loaded with data', () => {
        const wrapper = mount(AsyncState, {
            props: { loading: false, error: null, empty: false },
            slots: { default: content },
        });

        expect(wrapper.find('.loaded').exists()).toBe(true);
    });
});
