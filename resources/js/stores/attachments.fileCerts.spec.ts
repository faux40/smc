import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAttachmentsStore } from '@/stores/attachments';

vi.mock('axios');
vi.mock('@/composables/useRealtime', () => ({
    useRealtime: () => ({ bind: vi.fn() }),
}));

const TYPE = 'App\\Models\\TrainingClass';

describe('attachments store — fileClassCertificates', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('POSTs to the class cert endpoint then reloads that class list', async () => {
        const post = axios.post as ReturnType<typeof vi.fn>;
        post.mockResolvedValue({ data: { id: 'a1', filename: 'Certificates_X_20260620_0815.pdf' } });
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: [
                {
                    id: 'a1',
                    attachable_type: TYPE,
                    attachable_id: 'c1',
                    filename: 'Certificates_X_20260620_0815.pdf',
                    mime: 'application/pdf',
                    size: null,
                    uploaded_by_user_id: 'u1',
                    uploaded_by_name: 'Pat Lee',
                    created_at: '2026-06-20 08:15:00',
                    can_delete: true,
                },
            ],
        });

        const store = useAttachmentsStore();
        await store.fileClassCertificates('c1');

        expect(post).toHaveBeenCalledWith(
            '/api/classes/c1/certificates',
            {},
            expect.anything(),
        );
        // The class's attachment list now carries the filed certificate.
        const rows = store.listFor({ type: TYPE, id: 'c1' });
        expect(rows).toHaveLength(1);
        expect(rows[0].filename).toBe('Certificates_X_20260620_0815.pdf');
    });
});
