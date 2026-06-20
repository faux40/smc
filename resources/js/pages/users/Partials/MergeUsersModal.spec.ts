import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import MergeUsersModal from '@/pages/users/Partials/MergeUsersModal.vue';
import { useUsersStore } from '@/stores/users';
import type { MergePreview, UserRow } from '@/stores/users';

vi.mock('axios');
vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

function user(id: string, name: string): UserRow {
    return {
        id,
        name,
        sort_name: name,
        f_name: name,
        m_name: null,
        l_name: name,
        prefix_name: null,
        suffix_name: null,
        email: `${id}@example.com`,
        status: 'active',
        role: 'None',
        department: null,
        location: null,
        job_title: null,
        employee_number: null,
        supervisor_id: null,
        supervisor_name: null,
        supervisor_sort_name: null,
        start_date: null,
        end_date: null,
        notes: null,
        created_at: null,
        tag_ids: [],
        can_edit: true,
        can_disable: true,
        can_delete: true,
    };
}

const preview: MergePreview = {
    survivor: { id: 's', name: 'Keep', email: 's@example.com' },
    duplicate: { id: 'd', name: 'Drop', email: 'd@example.com' },
    fields: [
        {
            key: 'job_title',
            label: 'Job title',
            survivor: 'Operator',
            duplicate: 'Lead',
            differs: true,
            default: 'survivor',
        },
    ],
    role: { survivor: 'None', duplicate: 'None' },
    counts: { completions: 3 },
};

describe('MergeUsersModal', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        document.body.innerHTML = '';
    });

    async function openModal() {
        const store = useUsersStore();
        vi.spyOn(store, 'loadPicker').mockResolvedValue();
        store.hydrate([user('s', 'Keep'), user('d', 'Drop')]);
        const previewSpy = vi
            .spyOn(store, 'mergePreview')
            .mockResolvedValue(preview);
        const mergeSpy = vi.spyOn(store, 'merge').mockResolvedValue();

        const wrapper = mount(MergeUsersModal, {
            props: { open: false },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });
        await flushPromises();

        return { wrapper, previewSpy, mergeSpy };
    }

    // The dialog teleports to document.body, so query there (not the wrapper).
    const q = <T extends HTMLElement>(sel: string) =>
        document.body.querySelector<T>(sel);

    async function pickPair() {
        const lists = document.body.querySelectorAll('ul');
        lists[0].querySelectorAll('li')[0].click(); // survivor = s
        await flushPromises();
        // The duplicate list re-filters to exclude the survivor; after the
        // re-render its first row is the other user (d).
        document.body
            .querySelectorAll('ul')[1]
            .querySelectorAll('li')[0]
            .click(); // duplicate = d
        await flushPromises();
    }

    it('keeps Review disabled until a distinct survivor + duplicate are chosen', async () => {
        await openModal();

        const reviewBtn = q<HTMLButtonElement>(
            '[data-testid="merge-preview-btn"]',
        )!;
        expect(reviewBtn.disabled).toBe(true);

        await pickPair();

        expect(reviewBtn.disabled).toBe(false);
    });

    it('previews the diff then merges on confirm', async () => {
        const { wrapper, previewSpy, mergeSpy } = await openModal();

        await pickPair();
        q<HTMLButtonElement>('[data-testid="merge-preview-btn"]')!.click();
        await flushPromises();

        expect(previewSpy).toHaveBeenCalledWith('s', 'd');
        // The conflicting field is surfaced for resolution.
        expect(q('[data-testid="conflict-job_title"]')).not.toBeNull();

        q<HTMLButtonElement>('[data-testid="merge-confirm-btn"]')!.click();
        await flushPromises();

        expect(mergeSpy).toHaveBeenCalledWith({
            survivor_id: 's',
            duplicate_id: 'd',
            fields: { job_title: 'survivor' },
        });
        expect(wrapper.emitted('merged')).toBeTruthy();
        expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false]);
    });
});
