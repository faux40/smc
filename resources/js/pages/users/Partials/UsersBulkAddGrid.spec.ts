import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import UsersBulkAddGrid from '@/pages/users/Partials/UsersBulkAddGrid.vue';
import { useUsersStore } from '@/stores/users';

function mountGrid(existingEmails: string[] = []) {
    return mount(UsersBulkAddGrid, {
        props: {
            existingEmails,
            roles: ['None', 'Manager', 'Admin'],
            supervisors: [{ id: 's1', name: 'Boss Person' }],
            fieldOptions: {
                department: ['Ops'],
                location: ['Site A'],
                job_title: ['Tech'],
            },
        },
    });
}

async function setCell(
    wrapper: ReturnType<typeof mountGrid>,
    label: string,
    value: string,
) {
    const input = wrapper.find(`input[aria-label="${label}"]`);
    await input.setValue(value);
}

describe('UsersBulkAddGrid', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('starts with rows and can add/remove them', async () => {
        const wrapper = mountGrid();
        expect(wrapper.findAll('[data-testid="bulk-row"]')).toHaveLength(3);

        const addRow = wrapper
            .findAll('button')
            .find((b) => b.text() === '+ Row')!;
        await addRow.trigger('click');
        expect(wrapper.findAll('[data-testid="bulk-row"]')).toHaveLength(4);

        await wrapper
            .find('button[aria-label="Remove row 1"]')
            .trigger('click');
        expect(wrapper.findAll('[data-testid="bulk-row"]')).toHaveLength(3);
    });

    it('flags a duplicate email within the batch and blocks submit', async () => {
        const wrapper = mountGrid();
        await setCell(wrapper, 'First * row 1', 'Ada');
        await setCell(wrapper, 'Last * row 1', 'Lovelace');
        await setCell(wrapper, 'Email row 1', 'dup@x.com');
        await setCell(wrapper, 'First * row 2', 'Grace');
        await setCell(wrapper, 'Last * row 2', 'Hopper');
        await setCell(wrapper, 'Email row 2', 'dup@x.com');

        expect(wrapper.text()).toContain('Duplicate in this batch');
        const submitBtn = wrapper.findAll('button').at(-1)!;
        expect(submitBtn.attributes('disabled')).toBeDefined();
    });

    it('flags an email already used by an existing user', async () => {
        const wrapper = mountGrid(['taken@x.com']);
        await setCell(wrapper, 'First * row 1', 'A');
        await setCell(wrapper, 'Last * row 1', 'B');
        await setCell(wrapper, 'Email row 1', 'TAKEN@x.com');
        expect(wrapper.text()).toContain('Already in use');
    });

    it('submits only filled rows, shows the summary, and maps server errors back', async () => {
        const wrapper = mountGrid();
        const store = useUsersStore();
        const spy = vi.spyOn(store, 'bulkCreate').mockResolvedValue({
            created: 1,
            skipped: 1,
            results: [
                { index: 0, status: 'created', user_id: 'u1' },
                {
                    index: 1,
                    status: 'skipped',
                    errors: { email: ['Already in use'] },
                },
            ],
        });

        await setCell(wrapper, 'First * row 1', 'Ada');
        await setCell(wrapper, 'Last * row 1', 'Lovelace');
        await setCell(wrapper, 'Email row 1', 'ada@x.com');
        await setCell(wrapper, 'First * row 2', 'Grace');
        await setCell(wrapper, 'Last * row 2', 'Hopper');
        await setCell(wrapper, 'Email row 2', 'grace@x.com');

        await wrapper.findAll('button').at(-1)!.trigger('click'); // submit
        await flushPromises();

        // Only the two touched rows were sent (blank 3rd row excluded).
        expect(spy).toHaveBeenCalledTimes(1);
        expect(spy.mock.calls[0][0]).toHaveLength(2);

        expect(wrapper.find('[data-testid="bulk-summary"]').text()).toContain(
            'Added 1',
        );
        // Created row removed; the skipped row remains showing its server error.
        expect(wrapper.text()).toContain('Already in use');
        expect(wrapper.emitted('done')).toBeTruthy();
        // Something was skipped → grid stays open (no close).
        expect(wrapper.emitted('close')).toBeFalsy();
    });

    it('emits close when every row is accepted', async () => {
        const wrapper = mountGrid();
        const store = useUsersStore();
        vi.spyOn(store, 'bulkCreate').mockResolvedValue({
            created: 1,
            skipped: 0,
            results: [{ index: 0, status: 'created', user_id: 'u1' }],
        });

        await setCell(wrapper, 'First * row 1', 'Ada');
        await setCell(wrapper, 'Last * row 1', 'Lovelace');
        await setCell(wrapper, 'Email row 1', 'ada@x.com');

        await wrapper.findAll('button').at(-1)!.trigger('click');
        await flushPromises();

        expect(wrapper.emitted('done')).toBeTruthy();
        expect(wrapper.emitted('close')).toBeTruthy();
    });

    it('emits created with the new user ids so callers can act on them', async () => {
        const wrapper = mountGrid();
        const store = useUsersStore();
        vi.spyOn(store, 'bulkCreate').mockResolvedValue({
            created: 1,
            skipped: 0,
            results: [{ index: 0, status: 'created', user_id: 'u42' }],
        });

        await setCell(wrapper, 'First * row 1', 'Ada');
        await setCell(wrapper, 'Last * row 1', 'Lovelace');

        await wrapper.findAll('button').at(-1)!.trigger('click');
        await flushPromises();

        expect(wrapper.emitted('created')).toEqual([[['u42']]]);
    });

    it('does not call the store when nothing is filled in', async () => {
        const wrapper = mountGrid();
        const store = useUsersStore();
        const spy = vi.spyOn(store, 'bulkCreate');
        await wrapper.findAll('button').at(-1)!.trigger('click');
        await flushPromises();
        expect(spy).not.toHaveBeenCalled();
    });
});
