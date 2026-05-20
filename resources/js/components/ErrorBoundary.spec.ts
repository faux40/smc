import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import ErrorBoundary from '@/components/ErrorBoundary.vue';

// The boundary subscribes to Inertia navigations and can trigger a reload;
// stub the router so the component is testable in isolation.
vi.mock('@inertiajs/vue3', () => ({
    router: {
        on: vi.fn(() => vi.fn()),
        reload: vi.fn(),
    },
}));

const Boom = defineComponent({
    setup() {
        throw new Error('kaboom');
    },
    render: () => h('div'),
});

describe('ErrorBoundary', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders its default slot when no error occurs', () => {
        const wrapper = mount(ErrorBoundary, {
            slots: {
                default: () => h('div', { class: 'child' }, 'page content'),
            },
        });

        expect(wrapper.find('.child').exists()).toBe(true);
        expect(wrapper.text()).toContain('page content');
        expect(wrapper.text()).not.toContain('Something went wrong');
    });

    it('shows the fallback when a child throws', async () => {
        const wrapper = mount(ErrorBoundary, {
            slots: { default: () => h(Boom) },
        });
        // onErrorCaptured sets the error ref; the fallback renders next tick.
        await nextTick();

        expect(wrapper.text()).toContain('Something went wrong');
        expect(wrapper.find('button').exists()).toBe(true);
    });

    it('reloads via the Inertia router when the fallback button is clicked', async () => {
        const wrapper = mount(ErrorBoundary, {
            slots: { default: () => h(Boom) },
        });
        await nextTick();

        await wrapper.find('button').trigger('click');
        await flushPromises();

        expect(router.reload).toHaveBeenCalledOnce();
    });
});
