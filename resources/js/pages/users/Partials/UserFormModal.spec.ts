import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ComboboxInput from '@/components/ComboboxInput.vue';
import UserFormModal from '@/pages/users/Partials/UserFormModal.vue';
import { useUsersStore } from '@/stores/users';

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

describe('UserFormModal — inline create mode', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        document.body.innerHTML = '';
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: fieldOptions,
        });
    });

    it('creates via the non-navigating path and emits the new user', async () => {
        const created = {
            id: 'new1',
            sort_name: 'Lovelace, Ada',
            name: 'Ada Lovelace',
        };
        const wrapper = mount(UserFormModal, {
            props: { open: false, mode: 'create', inline: true },
            attachTo: document.body,
        });
        const store = useUsersStore();
        const spy = vi
            .spyOn(store, 'createReturning')
            .mockResolvedValue(created as never);
        const inertia = vi.spyOn(store, 'create');

        await wrapper.setProps({ open: true });
        await flushPromises();

        // The dialog teleports to <body>, so drive it via the document.
        const setInput = (id: string, value: string) => {
            const el = document.querySelector<HTMLInputElement>(`#${id}`)!;
            el.value = value;
            el.dispatchEvent(new Event('input', { bubbles: true }));
        };
        setInput('user_f_name', 'Ada');
        setInput('user_l_name', 'Lovelace');
        document
            .querySelector('form')!
            .dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        await flushPromises();

        expect(spy).toHaveBeenCalledTimes(1);
        expect(inertia).not.toHaveBeenCalled();
        expect(wrapper.emitted('created')).toEqual([[created]]);
        expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false]);
    });
});
