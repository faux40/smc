import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AttachmentViewer from '@/components/AttachmentViewer.vue';
import ComboboxInput from '@/components/ComboboxInput.vue';
import { useAttachmentsStore } from '@/stores/attachments';
import type { AttachmentRow } from '@/stores/attachments';

vi.mock('vue-sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

function row(overrides: Partial<AttachmentRow> = {}): AttachmentRow {
    return {
        id: 'a1',
        attachable_type: 'App\\Models\\TrainingClass',
        attachable_id: 'c1',
        filename: 'file.pdf',
        type: null,
        description: null,
        mime: 'application/pdf',
        size: 1024,
        uploaded_by_user_id: 'u1',
        uploaded_by_name: 'Dana Reed',
        created_at: '2026-06-01 10:00:00',
        can_delete: true,
        can_edit: false,
        ...overrides,
    };
}

async function openWith(attachment: AttachmentRow) {
    const wrapper = mount(AttachmentViewer, {
        props: { open: false, attachment },
        attachTo: document.body,
    });
    await wrapper.setProps({ open: true });
    await flushPromises();

    return wrapper;
}

describe('AttachmentViewer', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        document.body.innerHTML = '';
    });

    it('previews a PDF in an iframe pointed at the inline view URL', async () => {
        await openWith(row({ id: 'a1', mime: 'application/pdf' }));

        const iframe = document.body.querySelector('iframe');
        expect(iframe).not.toBeNull();
        expect(iframe?.getAttribute('src')).toBe('/api/attachments/a1/view');
        // No raw <img> for a PDF.
        expect(document.body.querySelector('img')).toBeNull();
    });

    it('previews an image in an <img> pointed at the inline view URL', async () => {
        await openWith(row({ id: 'a2', mime: 'image/png' }));

        const img = document.body.querySelector('img');
        expect(img).not.toBeNull();
        expect(img?.getAttribute('src')).toBe('/api/attachments/a2/view');
        expect(document.body.querySelector('iframe')).toBeNull();
    });

    it('shows a no-preview message for an unsupported type', async () => {
        await openWith(
            row({
                mime: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            }),
        );

        expect(document.body.querySelector('iframe')).toBeNull();
        expect(document.body.querySelector('img')).toBeNull();
        expect(document.body.textContent).toContain('No preview');
    });

    it('shows a fallback message for unpreviewable types (no download button)', async () => {
        await openWith(row({ id: 'a3', mime: 'application/zip' }));

        // The viewer no longer carries its own Download button — the browser's
        // built-in preview controls / the file menu handle downloading.
        const dl = Array.from(
            document.body.querySelectorAll<HTMLAnchorElement>('a'),
        ).find((a) => a.getAttribute('href') === '/api/attachments/a3/download');
        expect(dl).toBeUndefined();
        expect(document.body.textContent).toContain('No preview available');
    });

    it('edits a stored attachment’s details when permitted', async () => {
        const store = useAttachmentsStore();
        vi.spyOn(store, 'loadTypes').mockResolvedValue();
        const update = vi.spyOn(store, 'updateInfo').mockResolvedValue();

        const wrapper = await openWith(row({ id: 'a4', can_edit: true, type: 'Old' }));

        document.body
            .querySelector<HTMLButtonElement>('[data-testid="viewer-edit"]')!
            .click();
        await flushPromises();

        wrapper
            .findComponent(ComboboxInput)
            .vm.$emit('update:modelValue', 'Sign-in sheet');
        await flushPromises();
        document.body
            .querySelector<HTMLButtonElement>('[data-testid="viewer-save"]')!
            .click();
        await flushPromises();

        expect(update).toHaveBeenCalledWith('a4', {
            type: 'Sign-in sheet',
            description: '',
        });
    });

    it('hides the edit affordance when can_edit is false', async () => {
        await openWith(row({ id: 'a5', can_edit: false }));
        expect(
            document.body.querySelector('[data-testid="viewer-edit"]'),
        ).toBeNull();
    });

    it('previews a generated doc and files it via save-to-files', async () => {
        const store = useAttachmentsStore();
        vi.spyOn(store, 'loadTypes').mockResolvedValue();
        const file = vi.spyOn(store, 'fileClassDocument').mockResolvedValue();

        const wrapper = mount(AttachmentViewer, {
            props: {
                open: false,
                generated: {
                    title: 'Certificates',
                    src: '/api/classes/c1/certificates',
                    classId: 'c1',
                    kind: 'certificates',
                },
            },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });
        await flushPromises();

        // Preview points at the generate endpoint.
        expect(document.body.querySelector('iframe')?.getAttribute('src')).toBe(
            '/api/classes/c1/certificates',
        );

        document.body
            .querySelector<HTMLButtonElement>(
                '[data-testid="viewer-save-to-files"]',
            )!
            .click();
        await flushPromises();
        document.body
            .querySelector<HTMLButtonElement>('[data-testid="viewer-save"]')!
            .click();
        await flushPromises();

        expect(file).toHaveBeenCalledWith('c1', 'certificates', {
            type: '',
            description: '',
        });
    });
});
