import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import GenerateBar from '@/pages/documents/Partials/GenerateBar.vue';
import { useDocTemplatesStore } from '@/stores/docTemplates';
import type { DocTemplateRow } from '@/stores/docTemplates';
import { useGeneratedDocumentsStore } from '@/stores/generatedDocuments';
import { useMergeDataStore } from '@/stores/mergeData';

vi.mock('axios');
vi.mock('@/echo', () => ({ realtimeTabId: () => 'test-tab' }));
vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('@/routes/documents', () => ({
    data: () => ({ url: '/documents/data' }),
    page: () => ({ url: '/documents' }),
}));
vi.mock('@/composables/useRealtime', () => ({
    useRealtime: vi.fn(() => ({ bind: vi.fn(), leave: vi.fn() })),
}));

function template(overrides: Partial<DocTemplateRow> & { id: string }): DocTemplateRow {
    return {
        name: overrides.id,
        description: null,
        original_filename: 'x.docx',
        extension: 'docx',
        size: 1000,
        placeholders: ['agency'],
        version: 1,
        is_system: true,
        can_edit: false,
        can_delete: false,
        updated_at: null,
        ...overrides,
    };
}

describe('GenerateBar', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    function setup(placeholders: string[] = ['agency']) {
        const templates = useDocTemplatesStore();
        templates.library = [template({ id: 't1', name: 'HazCom', placeholders })];

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
        mergeData.values = [];

        const generated = useGeneratedDocumentsStore();
        generated.generate = vi.fn().mockResolvedValue({ id: 'g1', status: 'queued' });

        const wrapper = mount(GenerateBar);

        return { wrapper, generated };
    }

    it('generates with the selected template and variation', async () => {
        const { wrapper, generated } = setup();

        await wrapper.get('[data-testid="generate-template"]').setValue('t1');
        await wrapper.get('input#generate-location').setValue('North Yard');
        await wrapper.get('[data-testid="generate-btn"]').trigger('click');
        await flushPromises();

        expect(generated.generate).toHaveBeenCalledWith('t1', 'North Yard', '');
    });

    it('disables generate until a template is picked', () => {
        const { wrapper } = setup();

        expect(
            wrapper.get('[data-testid="generate-btn"]').attributes('disabled'),
        ).toBeDefined();
    });

    it('warns about placeholder keys with no data', async () => {
        const { wrapper } = setup(['agency', 'top_manager']);

        // agency is a field with no value; top_manager is not even a field.
        await wrapper.get('[data-testid="generate-template"]').setValue('t1');
        await flushPromises();

        const warning = wrapper.get('[data-testid="missing-data-warning"]');
        expect(warning.text()).toContain('1 field has no data');
        expect(warning.text()).toContain('agency');
    });

    it('shows no warning when everything resolves', async () => {
        const { wrapper } = setup();
        const mergeData = useMergeDataStore();
        mergeData.values = [
            { id: 'v1', merge_field_id: 'f1', location: '', department: '', value: 'Rio Dell' },
        ];

        await wrapper.get('[data-testid="generate-template"]').setValue('t1');
        await flushPromises();

        expect(wrapper.find('[data-testid="missing-data-warning"]').exists()).toBe(false);
    });
});
