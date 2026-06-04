import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AttachmentsList from '@/components/AttachmentsList.vue';

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth: { user: { org_id: 'org1' } } } }),
}));

const pdfRow = {
    id: 'a1',
    attachable_type: 'App\\Models\\TrainingClass',
    attachable_id: 'c1',
    filename: 'handout.pdf',
    mime: 'application/pdf',
    size: 2048,
    uploaded_by_user_id: 'u1',
    uploaded_by_name: 'Dana Reed',
    created_at: '2026-06-01 10:00:00',
    can_delete: true,
};

async function mountList() {
    (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
        data: [pdfRow],
    });
    const wrapper = mount(AttachmentsList, {
        props: {
            morphableType: 'App\\Models\\TrainingClass',
            morphableId: 'c1',
        },
        attachTo: document.body,
    });
    await flushPromises();

    return wrapper;
}

describe('AttachmentsList', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        document.body.innerHTML = '';
    });

    it('opens the embedded viewer when the filename is clicked', async () => {
        const wrapper = await mountList();

        // No preview iframe until the user opens the viewer.
        expect(document.body.querySelector('iframe')).toBeNull();

        const trigger = wrapper
            .findAll('button')
            .find((b) => b.text() === 'handout.pdf');
        expect(trigger).toBeTruthy();

        await trigger!.trigger('click');
        await flushPromises();

        const iframe = document.body.querySelector('iframe');
        expect(iframe?.getAttribute('src')).toBe('/api/attachments/a1/view');
    });

    it('offers a direct download link per row', async () => {
        const wrapper = await mountList();

        const dl = wrapper
            .findAll('a')
            .find(
                (a) =>
                    a.attributes('href') === '/api/attachments/a1/download',
            );
        expect(dl).toBeTruthy();
    });
});
