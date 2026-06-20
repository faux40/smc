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
