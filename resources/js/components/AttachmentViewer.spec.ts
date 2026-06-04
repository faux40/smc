import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it } from 'vitest';
import AttachmentViewer from '@/components/AttachmentViewer.vue';
import type { AttachmentRow } from '@/stores/attachments';

function row(overrides: Partial<AttachmentRow> = {}): AttachmentRow {
    return {
        id: 'a1',
        attachable_type: 'App\\Models\\TrainingClass',
        attachable_id: 'c1',
        filename: 'file.pdf',
        mime: 'application/pdf',
        size: 1024,
        uploaded_by_user_id: 'u1',
        uploaded_by_name: 'Dana Reed',
        created_at: '2026-06-01 10:00:00',
        can_delete: true,
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

    it('always offers a download link to the attachment disposition URL', async () => {
        await openWith(row({ id: 'a3', mime: 'application/zip' }));

        const link = Array.from(
            document.body.querySelectorAll<HTMLAnchorElement>('a'),
        ).find((a) => a.getAttribute('href') === '/api/attachments/a3/download');
        expect(link).toBeTruthy();
    });
});
