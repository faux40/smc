import { mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';
import { blankTrainingForm } from '@/lib/trainingForm';
import TrainingFields from '@/pages/trainings/Partials/TrainingFields.vue';
import { useCardTemplatesStore } from '@/stores/cardTemplates';

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { org: { name: 'Test Org' } } }),
}));

describe('TrainingFields', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({ data: [] });
    });

    it('two-way binds a field to the model', async () => {
        const form = reactive(blankTrainingForm());
        const wrapper = mount(TrainingFields, {
            props: { modelValue: form, context: 'form:training' },
        });
        await nextTick();

        await wrapper.get('#t_name').setValue('CPR Basics');

        expect(form.name).toBe('CPR Basics');
    });

    it('clears the frequency when repeating is turned off', async () => {
        const form = reactive({
            ...blankTrainingForm(),
            repeating: true,
            std_freq_id: 'freq-1',
        });
        mount(TrainingFields, {
            props: { modelValue: form, context: 'form:training' },
        });
        await nextTick();

        form.repeating = false;
        await nextTick();

        expect(form.std_freq_id).toBeNull();
    });

    it('picks the custom card template printed for this training', async () => {
        const templates = useCardTemplatesStore();
        templates.library = [
            {
                id: 'tpl-1',
                name: 'CPR wallet card',
                description: null,
                original_filename: 'cpr.pptx',
                extension: 'pptx',
                size: 1,
                placeholders: [],
                fonts: [],
                unsupported_fonts: [],
                slide_count: 1,
                has_back: false,
                card_width: 243,
                card_height: 153,
                version: 1,
                is_system: false,
                can_edit: true,
                can_delete: true,
                updated_at: null,
            },
        ];
        templates.loaded = true;

        const form = reactive(blankTrainingForm());
        const wrapper = mount(TrainingFields, {
            props: { modelValue: form, context: 'form:training' },
        });
        await nextTick();

        const select = wrapper.get('#t_card_template');
        expect(select.text()).toContain('CPR wallet card');

        await select.setValue('tpl-1');
        expect(form.card_template_id).toBe('tpl-1');

        // "No card" must detach rather than send an empty string.
        await select.setValue('');
        expect(form.card_template_id).toBeNull();
    });

    it('heads the built-in certificate block "SMC Certificate"', async () => {
        const form = reactive(blankTrainingForm());
        const wrapper = mount(TrainingFields, {
            props: { modelValue: form, context: 'form:training' },
        });
        await nextTick();

        // Distinguishes it from an uploaded custom card template.
        expect(wrapper.text()).toContain('SMC Certificate');
    });
});
