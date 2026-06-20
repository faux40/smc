import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ClassCertificatesSaveButton from '@/pages/classes/Partials/ClassCertificatesSaveButton.vue';
import { useAttachmentsStore } from '@/stores/attachments';

vi.mock('vue-sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

describe('ClassCertificatesSaveButton', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        document.body.innerHTML = '';
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('confirms via the popup and files the certificates through the store', async () => {
        const wrapper = mount(ClassCertificatesSaveButton, {
            props: { classId: 'c1' },
            attachTo: document.body,
        });
        const store = useAttachmentsStore();
        const spy = vi
            .spyOn(store, 'fileClassCertificates')
            .mockResolvedValue();

        // Popup not open yet → no confirm action.
        expect(
            document.body.querySelector('[data-testid="save-certs-confirm"]'),
        ).toBeNull();

        await wrapper.find('[data-testid="save-certs-btn"]').trigger('click');
        await flushPromises();

        const confirmBtn = document.body.querySelector<HTMLButtonElement>(
            '[data-testid="save-certs-confirm"]',
        );
        expect(confirmBtn).not.toBeNull();

        confirmBtn!.click();
        await flushPromises();

        expect(spy).toHaveBeenCalledWith('c1');
    });
});
