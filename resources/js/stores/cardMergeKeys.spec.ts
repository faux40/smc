import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useCardMergeKeysStore } from '@/stores/cardMergeKeys';

vi.mock('axios');
vi.mock('@/echo', () => ({ realtimeTabId: () => 'test-tab' }));

const catalogue = [
    {
        group: 'Person',
        keys: [{ key: 'first_name', placeholder: '${first_name}' }],
    },
];

describe('useCardMergeKeysStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('loads the catalogue once and caches it', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({ data: catalogue });
        const store = useCardMergeKeysStore();

        await store.load();
        await store.load();

        // A fixed vocabulary that only changes with a deploy — one fetch a
        // session is plenty.
        expect(get).toHaveBeenCalledTimes(1);
        expect(get).toHaveBeenCalledWith(
            '/api/card-merge-keys',
            expect.anything(),
        );
        expect(store.groups[0].keys[0].key).toBe('first_name');
    });
});
