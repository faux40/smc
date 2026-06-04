import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ComboboxInput from '@/components/ComboboxInput.vue';
import UserFormModal from '@/pages/users/Partials/UserFormModal.vue';

vi.mock('axios');

const fieldOptions = {
    department: ['Operations'],
    location: ['Yard 3'],
    job_title: ['Foreman'],
};

describe('UserFormModal — org field-value type-ahead', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        document.body.innerHTML = '';
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: fieldOptions,
        });
    });

    it('fetches org field options on open and feeds the three comboboxes', async () => {
        const wrapper = mount(UserFormModal, {
            props: { open: false, mode: 'create' },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });
        await flushPromises();

        expect(axios.get).toHaveBeenCalledWith('/api/users/field-options');

        const byId = Object.fromEntries(
            wrapper
                .findAllComponents(ComboboxInput)
                .map((c) => [c.props('id'), c.props('suggestions')]),
        );

        expect(byId['user_department']).toEqual(['Operations']);
        expect(byId['user_location']).toEqual(['Yard 3']);
        expect(byId['user_job_title']).toEqual(['Foreman']);
    });
});
