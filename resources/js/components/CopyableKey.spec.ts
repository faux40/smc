import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CopyableKey from '@/components/CopyableKey.vue';

const writeText = vi.fn();

function setClipboard(value: unknown): void {
    Object.defineProperty(navigator, 'clipboard', {
        value,
        configurable: true,
    });
}

describe('CopyableKey', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        writeText.mockResolvedValue(undefined);
        setClipboard({ writeText });
    });

    afterEach(() => {
        setClipboard(undefined);
    });

    it('shows the placeholder exactly as it must be typed', () => {
        const wrapper = mount(CopyableKey, {
            props: { text: '${first_name}' },
        });

        expect(wrapper.text()).toContain('${first_name}');
    });

    it('copies on click', async () => {
        const wrapper = mount(CopyableKey, { props: { text: '${cert_id}' } });

        await wrapper.get('[data-testid="copy-key"]').trigger('click');
        await flushPromises();

        expect(writeText).toHaveBeenCalledWith('${cert_id}');
    });

    it('confirms the copy, so the click is not a guess', async () => {
        const wrapper = mount(CopyableKey, { props: { text: '${cert_id}' } });

        expect(wrapper.text()).not.toContain('Copied');

        await wrapper.get('[data-testid="copy-key"]').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Copied');
    });

    it('admits when the browser would not let it copy', async () => {
        // Silence here is what made the first version look broken.
        setClipboard(undefined);
        document.execCommand = vi.fn().mockReturnValue(false) as never;
        const wrapper = mount(CopyableKey, { props: { text: '${hours}' } });

        await wrapper.get('[data-testid="copy-key"]').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Select it and copy');
    });

    it('leaves the text selectable for a manual copy', async () => {
        // A key trapped inside a button cannot be drag-selected, which is the
        // other half of "impossible to copy".
        const wrapper = mount(CopyableKey, { props: { text: '${today}' } });

        expect(wrapper.get('[data-testid="key-text"]').classes()).toContain(
            'select-all',
        );
    });

    it('is reachable and labelled for a screen reader', () => {
        const wrapper = mount(CopyableKey, { props: { text: '${email}' } });
        const button = wrapper.get('[data-testid="copy-key"]');

        expect(button.attributes('type')).toBe('button');
        expect(button.attributes('aria-label')).toBe('Copy ${email}');
    });
});
