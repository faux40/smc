import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAttachmentsStore } from '@/stores/attachments';

vi.mock('axios');
vi.mock('@/composables/useRealtime', () => ({
    useRealtime: () => ({ bind: vi.fn() }),
}));

const TYPE = 'App\\Models\\TrainingClass';

describe('attachments store — upload metadata + type vocabulary', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('uploads with type + description in the form data', async () => {
        const post = axios.post as ReturnType<typeof vi.fn>;
        post.mockResolvedValue({ data: { id: 'a1' } });
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({ data: [] });

        const store = useAttachmentsStore();
        const file = new File(['x'], 'roster.pdf', { type: 'application/pdf' });

        await store.upload({ type: TYPE, id: 'c1' }, file, {
            type: 'Sign-in sheet',
            description: 'Morning roster',
        });

        const fd = post.mock.calls[0][1] as FormData;
        expect(fd.get('type')).toBe('Sign-in sheet');
        expect(fd.get('description')).toBe('Morning roster');
        expect((fd.get('file') as File).name).toBe('roster.pdf');
    });

    it('omits type/description when not provided', async () => {
        const post = axios.post as ReturnType<typeof vi.fn>;
        post.mockResolvedValue({ data: { id: 'a1' } });
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({ data: [] });

        const store = useAttachmentsStore();
        const file = new File(['x'], 'f.pdf', { type: 'application/pdf' });

        await store.upload({ type: TYPE, id: 'c1' }, file);

        const fd = post.mock.calls[0][1] as FormData;
        expect(fd.get('type')).toBeNull();
        expect(fd.get('description')).toBeNull();
    });

    it('updateInfo PATCHes type/description and patches the cached row', async () => {
        const patch = axios.patch as ReturnType<typeof vi.fn>;
        patch.mockResolvedValue({
            data: { id: 'a1', type: 'Test', description: 'edited' },
        });
        const store = useAttachmentsStore();
        // Seed a loaded list containing the row.
        store.lists['App\\Models\\TrainingClass::c1'] = [
            {
                id: 'a1',
                attachable_type: TYPE,
                attachable_id: 'c1',
                filename: 'f.pdf',
                type: null,
                description: null,
                mime: 'application/pdf',
                size: null,
                uploaded_by_user_id: 'u1',
                uploaded_by_name: null,
                created_at: null,
                can_delete: true,
                can_edit: true,
            },
        ];

        await store.updateInfo('a1', { type: 'Test', description: 'edited' });

        expect(patch).toHaveBeenCalledWith(
            '/api/attachments/a1',
            { type: 'Test', description: 'edited' },
            expect.anything(),
        );
        const row = store.listFor({ type: TYPE, id: 'c1' })[0];
        expect(row.type).toBe('Test');
        expect(row.description).toBe('edited');
    });

    it('caches the type vocabulary; invalidate (or an upload) forces a refetch', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({ data: ['Sign-in sheet', 'Test'] });
        const store = useAttachmentsStore();

        await store.loadTypes();
        expect(store.types).toEqual(['Sign-in sheet', 'Test']);
        await store.loadTypes(); // cached
        expect(get).toHaveBeenCalledTimes(1);

        store.invalidateTypes();
        await store.loadTypes();
        expect(get).toHaveBeenCalledTimes(2);
        expect(get).toHaveBeenCalledWith(
            '/api/attachments/types',
            expect.anything(),
        );
    });
});
