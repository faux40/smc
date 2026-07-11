import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import MergeFieldValueRow from '@/components/mergeData/MergeFieldValueRow.vue';
import DocumentData from '@/pages/documents/Data.vue';

const { authUser } = vi.hoisted(() => ({
    authUser: {
        value: {
            id: 'me',
            org_id: 'org1',
            isAdmin: true,
            isManager: false,
        } as Record<string, unknown>,
    },
}));

vi.mock('axios');
vi.mock('@/echo', () => ({ realtimeTabId: () => 'test-tab' }));
vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    usePage: () => ({ props: { auth: { user: authUser.value } } }),
}));
vi.mock('@/routes/documents', () => ({ data: () => '/documents/data' }));
vi.mock('@/composables/useRealtime', () => ({
    useRealtime: vi.fn(() => ({ bind: vi.fn(), leave: vi.fn() })),
}));

const FIELDS = [
    {
        id: 'f1',
        key: 'agency',
        label: 'Agency name',
        type: 'text',
        field_group: 'Agency profile',
        help: null,
        seq: 0,
        draft: false,
        is_system: true,
        can_edit: false,
        can_delete: false,
    },
    {
        id: 'f2',
        key: 'top_manager',
        label: 'Top manager',
        type: 'text',
        field_group: 'Agency profile',
        help: null,
        seq: 1,
        draft: false,
        is_system: true,
        can_edit: false,
        can_delete: false,
    },
    {
        id: 'f3',
        key: 'eap_info',
        label: 'EAP info',
        type: 'list',
        field_group: 'Emergency',
        help: null,
        seq: 0,
        draft: false,
        is_system: true,
        can_edit: false,
        can_delete: false,
    },
];

const VALUES = [
    {
        id: 'v1',
        merge_field_id: 'f1',
        location: '',
        department: '',
        value: 'City of Rio Dell',
    },
];

function mockGets() {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation((url: string) => {
        if (url === '/api/merge-fields') {
return Promise.resolve({ data: FIELDS });
}

        if (url === '/api/merge-values') {
return Promise.resolve({ data: VALUES });
}

        if (url === '/api/users/field-options') {
return Promise.resolve({
                data: { department: ['Parks'], location: ['North Yard'], job_title: [] },
            });
}

        return Promise.resolve({ data: [] });
    });
}

async function mountPage() {
    mockGets();
    const wrapper = mount(DocumentData);
    await flushPromises();

    return wrapper;
}

describe('documents/Data page', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        authUser.value = { id: 'me', org_id: 'org1', isAdmin: true, isManager: false };
    });

    it('renders group sections with their field rows', async () => {
        const wrapper = await mountPage();

        expect(wrapper.text()).toContain('Agency profile');
        expect(wrapper.text()).toContain('Emergency');
        expect(wrapper.findAllComponents(MergeFieldValueRow)).toHaveLength(3);
    });

    it('shows per-group completeness for the current variation', async () => {
        const wrapper = await mountPage();

        // Agency profile: agency set, top_manager not → 1 of 2.
        expect(wrapper.text()).toContain('1 of 2 set');
        expect(wrapper.text()).toContain('0 of 1 set');
    });

    it('shows add-field for admins only', async () => {
        let wrapper = await mountPage();
        expect(wrapper.find('[data-testid="add-field"]').exists()).toBe(true);

        authUser.value = { id: 'me', org_id: 'org1', isAdmin: false, isManager: true };
        wrapper = await mountPage();
        expect(wrapper.find('[data-testid="add-field"]').exists()).toBe(false);
    });

    it('passes the picked variation down to the rows', async () => {
        const wrapper = await mountPage();

        const locationInput = wrapper.get('[data-testid="variation-location"] input');
        await locationInput.setValue('North Yard');
        await flushPromises();

        const row = wrapper.findAllComponents(MergeFieldValueRow)[0];
        expect(row.props('location')).toBe('North Yard');
    });
});
