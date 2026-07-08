import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CompletionFormModal from '@/pages/completions/Partials/CompletionFormModal.vue';
import { useCompletionsStore } from '@/stores/completions';
import type { CompletionRow } from '@/stores/completions';

vi.mock('axios');
vi.mock('@/echo', () => ({ realtimeTabId: () => 'test-tab' }));

// Select is reka-ui-backed (headless, portal-driven listbox) — not
// practical to drive through real DOM interaction in a unit test. Stub it
// (and its child parts) so `v-model`/`disabled` wiring is inspectable via
// props, same spirit as the Dialog stubs below.
const STUBS = {
    Dialog: { template: '<div v-if="open"><slot /></div>', props: ['open'] },
    DialogContent: { template: '<div><slot /></div>' },
    DialogHeader: { template: '<div><slot /></div>' },
    DialogTitle: { template: '<div><slot /></div>' },
    DialogDescription: { template: '<div><slot /></div>' },
    DialogFooter: { template: '<div><slot /></div>' },
    ErrorBanner: true,
    InputError: true,
    Select: true,
    SelectTrigger: true,
    SelectContent: true,
    SelectItem: true,
    SelectValue: true,
};

const USERS = [
    { id: 'u1', f_name: 'Alice', l_name: 'Adams', sort_name: 'Adams, Alice' },
    { id: 'u2', f_name: 'Bob', l_name: 'Baker', sort_name: 'Baker, Bob' },
];
const TRAININGS = [
    // No repeat frequency (as-needed/initial-only) — never auto-fills.
    { id: 't1', name: 'Fall Protection', repeating: false, std_freq_repeat_days: null },
    // Repeats annually — the auto-fill workhorse below.
    { id: 't2', name: 'First Aid', repeating: true, std_freq_repeat_days: 365 },
];

function mockAxios() {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation((url: string) => {
        if (url === '/api/users') {
return Promise.resolve({ data: USERS });
}

        if (url === '/api/trainings') {
return Promise.resolve({ data: TRAININGS });
}

        if (url === '/api/rqmt-elements/candidates') {
return Promise.resolve({ data: [] });
}

        return Promise.resolve({ data: [] });
    });
}

// The modal's reset-on-open watcher (like TrainingAssignmentFormModal's)
// only fires on a false→true transition, matching how every real caller
// uses it (`ref(false)`, flipped by a button). So mount closed and open it,
// unless the test explicitly wants to inspect the closed state.
async function mountModal(props: Record<string, unknown> = {}) {
    mockAxios();
    const wantsOpen = !('open' in props) || props.open !== false;
    const wrapper = mount(CompletionFormModal, {
        props: { mode: 'create', ...props, open: false },
        global: { stubs: STUBS },
    });
    await flushPromises();

    if (wantsOpen) {
        await wrapper.setProps({ open: true });
        await flushPromises();
    }

    return wrapper;
}

// Selects render in template order: user, module_type, module_id.
function selects(wrapper: ReturnType<typeof mount>) {
    return wrapper.findAllComponents({ name: 'Select' });
}

function completionTarget(overrides: Partial<CompletionRow> = {}): CompletionRow {
    return {
        id: 'c1',
        user_id: 'u1',
        module_type: 'App\\Models\\Training',
        module_id: 't2',
        training_name: 'First Aid',
        completion_date: '2026-01-01',
        certification_date: null,
        expire_date: null,
        cert_ident: null,
        cert_id: null,
        hours: null,
        class_training_id: null,
        class_id: null,
        class_name: null,
        notes: null,
        rqmt_element_ids: [],
        effective_element_ids: [],
        can_edit: true,
        can_delete: true,
        ...overrides,
    };
}

describe('CompletionFormModal — prefill props (F7)', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('preselects the user from initial-user-id and locks the user picker', async () => {
        const wrapper = await mountModal({ initialUserId: 'u1' });

        const [userSelect] = selects(wrapper);
        expect(userSelect.props('modelValue')).toBe('u1');
        expect(userSelect.props('disabled')).toBe(true);
    });

    it('preselects the training from initial-training-id and locks the module pickers', async () => {
        const wrapper = await mountModal({ initialTrainingId: 't2' });

        const [, moduleTypeSelect, moduleIdSelect] = selects(wrapper);
        expect(moduleIdSelect.props('modelValue')).toBe('t2');
        expect(moduleIdSelect.props('disabled')).toBe(true);
        expect(moduleTypeSelect.props('disabled')).toBe(true);
    });

    it('leaves both pickers enabled and empty in the standalone flow (no initial props)', async () => {
        const wrapper = await mountModal();

        const [userSelect, , moduleIdSelect] = selects(wrapper);
        expect(userSelect.props('disabled')).toBe(false);
        expect(userSelect.props('modelValue')).toBe('');
        expect(moduleIdSelect.props('disabled')).toBe(false);
        expect(moduleIdSelect.props('modelValue')).toBe('');
    });

    it('submits with the prefilled user + training ids (training id, not an assignment id)', async () => {
        const store = useCompletionsStore();
        const createSpy = vi.spyOn(store, 'create').mockResolvedValue({} as never);

        const wrapper = await mountModal({ initialUserId: 'u1', initialTrainingId: 't2' });
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(createSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                user_id: 'u1',
                module_id: 't2',
                module_type: 'App\\Models\\Training',
            }),
        );
    });

    it('emits saved and update:open=false after a successful create', async () => {
        const store = useCompletionsStore();
        vi.spyOn(store, 'create').mockResolvedValue({} as never);

        const wrapper = await mountModal({ initialUserId: 'u1', initialTrainingId: 't2' });
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(wrapper.emitted('saved')).toBeTruthy();
        expect(wrapper.emitted('update:open')).toEqual([[false]]);
    });

    it('re-applies the prefill each time the modal reopens', async () => {
        const wrapper = await mountModal({ initialUserId: 'u1', open: false });
        expect(selects(wrapper)).toHaveLength(0); // Dialog stub hides when closed

        await wrapper.setProps({ open: true });
        await flushPromises();

        const [userSelect] = selects(wrapper);
        expect(userSelect.props('modelValue')).toBe('u1');
    });

    it('does not disable the picker when no initial id is given, even alongside the other prefilled prop', async () => {
        const wrapper = await mountModal({ initialUserId: 'u1' });

        const [, , moduleIdSelect] = selects(wrapper);
        expect(moduleIdSelect.props('disabled')).toBe(false);
        expect(moduleIdSelect.props('modelValue')).toBe('');
    });
});

describe('CompletionFormModal — multi-user mode (F8)', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('replaces the user picker with a read-only summary of the selected users', async () => {
        const wrapper = await mountModal({
            userIds: ['u1', 'u2'],
            initialTrainingId: 't2',
        });

        const summary = wrapper.find('[data-testid="multi-user-summary"]');
        expect(summary.exists()).toBe(true);
        expect(summary.text()).toContain('Recording for 2 selected users');

        // No user <Select> in multi mode — only the two module selects remain.
        expect(selects(wrapper)).toHaveLength(2);
    });

    it('locks the training and submits via bulkCreate with the selected user ids', async () => {
        const store = useCompletionsStore();
        const bulkSpy = vi
            .spyOn(store, 'bulkCreate')
            .mockResolvedValue({ created_count: 2, skipped_count: 0 });

        const wrapper = await mountModal({
            userIds: ['u1', 'u2'],
            initialTrainingId: 't2',
        });

        // The module pickers are still locked from initialTrainingId.
        const [moduleTypeSelect, moduleIdSelect] = selects(wrapper);
        expect(moduleTypeSelect.props('disabled')).toBe(true);
        expect(moduleIdSelect.props('disabled')).toBe(true);

        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(bulkSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                user_ids: ['u1', 'u2'],
                training_id: 't2',
                completion_date: expect.any(String),
            }),
        );
    });

    it('emits saved with the bulk result and closes on success', async () => {
        const store = useCompletionsStore();
        vi.spyOn(store, 'bulkCreate').mockResolvedValue({
            created_count: 3,
            skipped_count: 1,
        });

        const wrapper = await mountModal({
            userIds: ['u1', 'u2', 'u3'],
            initialTrainingId: 't2',
        });
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(wrapper.emitted('saved')).toEqual([
            [{ created_count: 3, skipped_count: 1 }],
        ]);
        expect(wrapper.emitted('update:open')).toEqual([[false]]);
    });
});

describe('CompletionFormModal — expire_date auto-fill from training frequency (F9)', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('auto-fills expire_date when a repeating training is selected', async () => {
        const wrapper = await mountModal();
        await wrapper.find('#c_compdate').setValue('2026-03-01');

        const [, , moduleIdSelect] = selects(wrapper);
        moduleIdSelect.vm.$emit('update:modelValue', 't2'); // First Aid — 365 days
        await flushPromises();

        expect(wrapper.find<HTMLInputElement>('#c_expire').element.value).toBe(
            '2027-03-01',
        );
        expect(
            wrapper.find('[data-testid="expire-auto-helper"]').text(),
        ).toContain('Auto: 365 days from completion');
    });

    it('recomputes expire_date when completion_date changes', async () => {
        const wrapper = await mountModal({ initialTrainingId: 't2' });
        await wrapper.find('#c_compdate').setValue('2026-03-01');
        await flushPromises();
        expect(wrapper.find<HTMLInputElement>('#c_expire').element.value).toBe(
            '2027-03-01',
        );

        await wrapper.find('#c_compdate').setValue('2026-04-15');
        await flushPromises();

        expect(wrapper.find<HTMLInputElement>('#c_expire').element.value).toBe(
            '2027-04-15',
        );
    });

    it('a hand-typed expiry sticks — later training/date changes do not overwrite it', async () => {
        const wrapper = await mountModal({ initialTrainingId: 't2' });
        await wrapper.find('#c_compdate').setValue('2026-03-01');
        await flushPromises();
        expect(wrapper.find<HTMLInputElement>('#c_expire').element.value).toBe(
            '2027-03-01',
        );

        await wrapper.find('#c_expire').setValue('2030-01-01');
        await flushPromises();
        expect(
            wrapper.find('[data-testid="expire-auto-helper"]').exists(),
        ).toBe(false);

        await wrapper.find('#c_compdate').setValue('2026-04-01');
        await flushPromises();

        expect(wrapper.find<HTMLInputElement>('#c_expire').element.value).toBe(
            '2030-01-01',
        );
        expect(
            wrapper.find('[data-testid="expire-auto-helper"]').exists(),
        ).toBe(false);
    });

    it('leaves expire_date blank for a training with no repeat frequency', async () => {
        const wrapper = await mountModal({ initialTrainingId: 't1' });
        await flushPromises();

        expect(wrapper.find<HTMLInputElement>('#c_expire').element.value).toBe(
            '',
        );
        expect(
            wrapper.find('[data-testid="expire-auto-helper"]').exists(),
        ).toBe(false);
    });

    it('clears an auto-filled expiry when switching to a no-frequency training', async () => {
        const wrapper = await mountModal({ initialTrainingId: 't2' });
        await wrapper.find('#c_compdate').setValue('2026-03-01');
        await flushPromises();
        expect(wrapper.find<HTMLInputElement>('#c_expire').element.value).toBe(
            '2027-03-01',
        );

        const [, , moduleIdSelect] = selects(wrapper);
        moduleIdSelect.vm.$emit('update:modelValue', 't1'); // no frequency
        await flushPromises();

        expect(wrapper.find<HTMLInputElement>('#c_expire').element.value).toBe(
            '',
        );
    });

    it('auto-fills in multi-user (bulk) mode too', async () => {
        const wrapper = await mountModal({
            userIds: ['u1', 'u2'],
            initialTrainingId: 't2',
        });
        await wrapper.find('#c_compdate').setValue('2026-03-01');
        await flushPromises();

        expect(wrapper.find<HTMLInputElement>('#c_expire').element.value).toBe(
            '2027-03-01',
        );
    });

    it('edit mode does not auto-overwrite the stored expiry on open', async () => {
        const target = completionTarget({
            completion_date: '2026-01-01',
            expire_date: '2026-06-15',
        });
        const wrapper = await mountModal({ mode: 'edit', target });
        await flushPromises();

        expect(wrapper.find<HTMLInputElement>('#c_expire').element.value).toBe(
            '2026-06-15',
        );
        expect(
            wrapper.find('[data-testid="expire-auto-helper"]').exists(),
        ).toBe(false);
    });

    it('edit mode recomputes the expiry once the admin changes completion_date', async () => {
        const target = completionTarget({
            completion_date: '2026-01-01',
            expire_date: '2026-06-15',
        });
        const wrapper = await mountModal({ mode: 'edit', target });
        await flushPromises();

        await wrapper.find('#c_compdate').setValue('2026-02-01');
        await flushPromises();

        expect(wrapper.find<HTMLInputElement>('#c_expire').element.value).toBe(
            '2027-02-01',
        );
        expect(
            wrapper.find('[data-testid="expire-auto-helper"]').text(),
        ).toContain('Auto: 365 days from completion');
    });
});
