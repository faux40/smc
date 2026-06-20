import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ClassDocumentSaveModal from '@/pages/classes/Partials/ClassDocumentSaveModal.vue';
import { useAttachmentsStore } from '@/stores/attachments';

vi.mock('vue-sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

describe('ClassDocumentSaveModal', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        document.body.innerHTML = '';
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it.each(['certificates', 'summary'] as const)(
        'files the %s and closes when confirmed',
        async (kind) => {
            const wrapper = mount(ClassDocumentSaveModal, {
                props: { open: true, classId: 'c1', kind },
                attachTo: document.body,
            });
            await flushPromises();
            const store = useAttachmentsStore();
            const spy = vi
                .spyOn(store, 'fileClassDocument')
                .mockResolvedValue();

            const confirmBtn = document.body.querySelector<HTMLButtonElement>(
                '[data-testid="doc-save-modal-confirm"]',
            );
            expect(confirmBtn).not.toBeNull();
            // Copy reflects the kind.
            expect(document.body.textContent).toContain(kind);

            confirmBtn!.click();
            await flushPromises();

            expect(spy).toHaveBeenCalledWith('c1', kind);
            expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false]);
        },
    );

    it('renders nothing when closed', () => {
        mount(ClassDocumentSaveModal, {
            props: { open: false, classId: 'c1', kind: 'certificates' },
            attachTo: document.body,
        });

        expect(
            document.body.querySelector('[data-testid="doc-save-modal-confirm"]'),
        ).toBeNull();
    });
});
