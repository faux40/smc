import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useUsersStore } from '@/stores/users';
import type { UserRow } from '@/stores/users';

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({ router: {} }));

function user(overrides: Partial<UserRow>): UserRow {
    return {
        id: 'u',
        name: 'X',
        f_name: 'X',
        m_name: null,
        l_name: 'X',
        prefix_name: null,
        suffix_name: null,
        email: null,
        status: 'active',
        role: 'None',
        department: null,
        location: null,
        job_title: null,
        employee_number: null,
        supervisor_id: null,
        supervisor_name: null,
        start_date: null,
        end_date: null,
        notes: null,
        created_at: null,
        tag_ids: [],
        can_edit: true,
        can_disable: true,
        can_delete: true,
        ...overrides,
    };
}

describe('users store — combine users', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('mergePreview fetches the diff for the chosen pair', async () => {
        const preview = {
            survivor: { id: 's', name: 'Keep', email: null },
            duplicate: { id: 'd', name: 'Drop', email: null },
            fields: [],
            role: { survivor: 'None', duplicate: 'None' },
            counts: { completions: 2 },
        };
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: preview,
        });
        const store = useUsersStore();

        const result = await store.mergePreview('s', 'd');

        expect(axios.get).toHaveBeenCalledWith(
            '/api/users/merge-preview',
            expect.objectContaining({
                params: { survivor: 's', duplicate: 'd' },
            }),
        );
        expect(result.counts.completions).toBe(2);
    });

    it('merge patches the cache: drops the duplicate, updates the survivor', async () => {
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: {
                survivor: {
                    id: 's',
                    name: 'Kept Name',
                    email: 'kept@example.com',
                    status: 'active',
                },
                duplicate_id: 'd',
            },
        });
        const store = useUsersStore();
        store.hydrate([
            user({ id: 's', name: 'Old Name' }),
            user({ id: 'd', name: 'Drop' }),
        ]);

        await store.merge({
            survivor_id: 's',
            duplicate_id: 'd',
            fields: { job_title: 'duplicate' },
        });

        expect(axios.post).toHaveBeenCalledWith(
            '/users/merge',
            expect.objectContaining({ survivor_id: 's', duplicate_id: 'd' }),
            expect.anything(),
        );
        // Duplicate gone, survivor renamed from the response payload.
        expect(store.users.map((u) => u.id)).toEqual(['s']);
        expect(store.users[0].name).toBe('Kept Name');
        expect(store.users[0].email).toBe('kept@example.com');
    });
});
