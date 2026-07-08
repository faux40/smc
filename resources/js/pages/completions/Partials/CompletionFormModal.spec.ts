import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CompletionFormModal from '@/pages/completions/Partials/CompletionFormModal.vue';
import { useCompletionsStore } from '@/stores/completions';

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
    { id: 't1', name: 'Fall Protection' },
    { id: 't2', name: 'First Aid' },
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
