import axios from 'axios';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TrainingOrRequirementPicker from '@/components/TrainingOrRequirementPicker.vue';

vi.mock('axios');
vi.mock('@/echo', () => ({ realtimeTabId: () => 'test-tab' }));

function mockStores() {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation((url: string) => {
        if (url === '/api/requirements')
            return Promise.resolve({
                data: [
                    { id: 'r1', name: 'Forklift Package' },
                    { id: 'r2', name: 'Arc Flash Safety' },
                ],
            });
        if (url === '/api/trainings')
            return Promise.resolve({
                data: [
                    { id: 't1', name: 'Fall Protection' },
                    { id: 't2', name: 'Confined Space' },
                ],
            });
        return Promise.resolve({ data: [] });
    });
}

async function mountPicker(modelValue = '') {
    const wrapper = mount(TrainingOrRequirementPicker, {
        props: { modelValue },
    });
    await flushPromises();
    return wrapper;
}

describe('TrainingOrRequirementPicker', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        mockStores();
    });

    it('renders requirements before trainings', async () => {
        const wrapper = await mountPicker();
        const optgroups = wrapper.findAll('optgroup');
        expect(optgroups).toHaveLength(2);
        expect(optgroups[0].attributes('label')).toBe('Requirements');
        expect(optgroups[1].attributes('label')).toBe('Trainings');
    });

    it('lists all requirements in the first optgroup', async () => {
        const wrapper = await mountPicker();
        const reqGroup = wrapper.findAll('optgroup')[0];
        const names = reqGroup.findAll('option').map((o) => o.text());
        expect(names).toContain('Forklift Package');
        expect(names).toContain('Arc Flash Safety');
    });

    it('lists all trainings in the second optgroup', async () => {
        const wrapper = await mountPicker();
        const trainingGroup = wrapper.findAll('optgroup')[1];
        const names = trainingGroup.findAll('option').map((o) => o.text());
        expect(names).toContain('Fall Protection');
        expect(names).toContain('Confined Space');
    });

    it('emits update:modelValue with "requirement:{id}" when a requirement is selected', async () => {
        const wrapper = await mountPicker();
        await wrapper.find('[data-testid="item-select"]').setValue('requirement:r1');
        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['requirement:r1']);
    });

    it('emits update:modelValue with "training:{id}" when a training is selected', async () => {
        const wrapper = await mountPicker();
        await wrapper.find('[data-testid="item-select"]').setValue('training:t1');
        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['training:t1']);
    });

    it('reflects the modelValue as the selected option', async () => {
        const wrapper = await mountPicker('training:t2');
        const select = wrapper.find<HTMLSelectElement>('[data-testid="item-select"]');
        expect(select.element.value).toBe('training:t2');
    });

    it('disables the select when disabled prop is true', async () => {
        const wrapper = mount(TrainingOrRequirementPicker, {
            props: { modelValue: '', disabled: true },
        });
        await flushPromises();
        expect(
            (wrapper.find('[data-testid="item-select"]').element as HTMLSelectElement).disabled,
        ).toBe(true);
    });
});
