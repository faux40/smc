import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ClassCertificatesSaveModal from '@/pages/classes/Partials/ClassCertificatesSaveModal.vue';
import { useAttachmentsStore } from '@/stores/attachments';

vi.mock('vue-sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

describe('ClassCertificatesSaveModal', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        document.body.innerHTML = '';
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('files the certificates and closes when confirmed', async () => {
        const wrapper = mount(ClassCertificatesSaveModal, {
            props: { open: true, classId: 'c1' },
            attachTo: document.body,
        });
        await flushPromises();
        const store = useAttachmentsStore();
        const spy = vi.spyOn(store, 'fileClassCertificates').mockResolvedValue();

        const confirmBtn = document.body.querySelector<HTMLButtonElement>(
            '[data-testid="cert-save-modal-confirm"]',
        );
        expect(confirmBtn).not.toBeNull();

        confirmBtn!.click();
        await flushPromises();

        expect(spy).toHaveBeenCalledWith('c1');
        // Closes via v-model after a successful save.
        expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false]);
    });

    it('renders nothing when closed', () => {
        mount(ClassCertificatesSaveModal, {
            props: { open: false, classId: 'c1' },
            attachTo: document.body,
        });

        expect(
            document.body.querySelector('[data-testid="cert-save-modal-confirm"]'),
        ).toBeNull();
    });
});
