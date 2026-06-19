import { mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';
import { blankTrainingForm } from '@/lib/trainingForm';
import TrainingFields from '@/pages/trainings/Partials/TrainingFields.vue';

vi.mock('axios');

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
});
