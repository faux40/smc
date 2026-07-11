import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TemplatesList from '@/pages/documents/Partials/TemplatesList.vue';
import { useDocTemplatesStore } from '@/stores/docTemplates';
import type { DocTemplateRow } from '@/stores/docTemplates';
import { useMergeDataStore } from '@/stores/mergeData';

vi.mock('axios');
vi.mock('@/echo', () => ({ realtimeTabId: () => 'test-tab' }));
vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('@/composables/useRealtime', () => ({
    useRealtime: vi.fn(() => ({ bind: vi.fn(), leave: vi.fn() })),
}));

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

function template(overrides: Partial<DocTemplateRow> & { id: string }): DocTemplateRow {
    return {
        name: overrides.id,
        description: null,
        original_filename: 'x.docx',
        extension: 'docx',
        size: 1000,
        placeholders: ['agency'],
        version: 1,
        is_system: false,
        can_edit: true,
        can_delete: true,
        updated_at: null,
        ...overrides,
    };
}

describe('TemplatesList', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    function setup(rows: DocTemplateRow[], canDefine = true) {
        const store = useDocTemplatesStore();
        store.library = rows;
        store.upload = vi.fn().mockResolvedValue(rows[0] ?? template({ id: 'new' }));
        store.destroy = vi.fn().mockResolvedValue(undefined);

        const mergeData = useMergeDataStore();
        mergeData.reload = vi.fn().mockResolvedValue(undefined);

        const wrapper = mount(TemplatesList, {
            props: { canDefine },
            global: { stubs: STUBS },
        });

        return { wrapper, store };
    }

    it('shows upload and row actions for admins', () => {
        const { wrapper } = setup([template({ id: 't1' })]);

        expect(wrapper.find('[data-testid="upload-template"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="edit-template"]').exists()).toBe(true);
    });

    it('hides admin affordances for managers and on system rows', () => {
        const { wrapper } = setup(
            [template({ id: 't1', is_system: true, can_edit: false, can_delete: false })],
            false,
        );

        expect(wrapper.find('[data-testid="upload-template"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="edit-template"]').exists()).toBe(false);
    });

    it('flags templates whose fields lack org data', () => {
        const mergeData = useMergeDataStore();
        mergeData.fields = [
            {
                id: 'f1',
                key: 'agency',
                label: 'Agency',
                type: 'text',
                field_group: null,
                help: null,
                seq: 0,
                draft: false,
                is_system: true,
                can_edit: false,
                can_delete: false,
            },
        ];
        const { wrapper } = setup([template({ id: 't1', placeholders: ['agency'] })]);

        expect(wrapper.get('[data-testid="missing-count"]').text()).toContain('1 missing data');
    });

    it('uploads through the store and refreshes the field registry', async () => {
        const { wrapper, store } = setup([]);
        const mergeData = useMergeDataStore();

        await wrapper.get('[data-testid="upload-template"]').trigger('click');
        await wrapper.get('[data-testid="template-name"]').setValue('HazCom');

        const file = new File(['zip'], 'HazCom.docx');
        const input = wrapper.get('[data-testid="template-file"]');
        Object.defineProperty(input.element, 'files', { value: [file] });
        await input.trigger('change');

        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(store.upload).toHaveBeenCalledWith(file, 'HazCom', null);
        expect(mergeData.reload).toHaveBeenCalled();
    });
});
