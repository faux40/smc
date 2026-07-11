import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import MergeFieldFormModal from '@/components/mergeData/MergeFieldFormModal.vue';
import { useMergeDataStore } from '@/stores/mergeData';
import type { MergeFieldRow } from '@/stores/mergeData';

vi.mock('axios');
vi.mock('@/echo', () => ({ realtimeTabId: () => 'test-tab' }));
vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

function field(overrides: Partial<MergeFieldRow> = {}): MergeFieldRow {
    return {
        id: 'f1',
        key: 'union_rep',
        label: 'Union representative',
        type: 'text',
        field_group: 'Agency',
        help: 'Full name',
        seq: 0,
        draft: false,
        is_system: false,
        can_edit: true,
        can_delete: true,
        ...overrides,
    };
}

// Radix dialog teleports; render inline for the spec (house pattern —
// see TrainingAssignmentFormModal.spec).
const STUBS = {
    Dialog: { template: '<div v-if="open"><slot /></div>', props: ['open'] },
    DialogContent: { template: '<div><slot /></div>' },
    DialogHeader: { template: '<div><slot /></div>' },
    DialogTitle: { template: '<div><slot /></div>' },
    DialogDescription: { template: '<div><slot /></div>' },
    DialogFooter: { template: '<div><slot /></div>' },
    ErrorBanner: true,
    InputError: true,
};

function mountModal(editing: MergeFieldRow | null = null) {
    const store = useMergeDataStore();
    store.createField = vi.fn().mockResolvedValue(field());
    store.updateField = vi.fn().mockResolvedValue(undefined);

    const wrapper = mount(MergeFieldFormModal, {
        props: { open: true, editing },
        global: { stubs: STUBS },
    });

    return { store, wrapper };
}

describe('MergeFieldFormModal', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        document.body.innerHTML = '';
    });

    it('creates a field from the form values', async () => {
        const { store, wrapper } = mountModal();

        await wrapper.get('[data-testid="field-key"]').setValue('union_rep');
        await wrapper.get('[data-testid="field-label"]').setValue('Union representative');
        await wrapper.get('[data-testid="field-type"]').setValue('multiline');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(store.createField).toHaveBeenCalledWith(
            expect.objectContaining({
                key: 'union_rep',
                label: 'Union representative',
                type: 'multiline',
            }),
        );
        expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false]);
    });

    it('pre-fills and updates when editing', async () => {
        const editing = field();
        const { store, wrapper } = mountModal(editing);

        const key = wrapper.get('[data-testid="field-key"]')
            .element as HTMLInputElement;
        expect(key.value).toBe('union_rep');

        await wrapper.get('[data-testid="field-label"]').setValue('Union rep (primary)');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(store.updateField).toHaveBeenCalledWith(
            'f1',
            expect.objectContaining({ label: 'Union rep (primary)' }),
        );
    });

    it('keeps the dialog open when the store rejects', async () => {
        const { store, wrapper } = mountModal();
        (store.createField as ReturnType<typeof vi.fn>).mockRejectedValue({
            response: { status: 422, data: { errors: { key: ['taken'] } } },
        });

        await wrapper.get('[data-testid="field-key"]').setValue('agency');
        await wrapper.get('[data-testid="field-label"]').setValue('X');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.emitted('update:open') ?? []).toEqual([]);
    });
});
