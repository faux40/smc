import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import UserRowActions from '@/pages/users/Partials/UserRowActions.vue';
import type { UserRow } from '@/stores/users';

function row(overrides: Partial<UserRow> = {}): UserRow {
    return {
        id: 'u1',
        name: 'Pat Lee',
        sort_name: 'Lee, Pat',
        f_name: 'Pat',
        m_name: null,
        l_name: 'Lee',
        prefix_name: null,
        suffix_name: null,
        email: null,
        status: 'active',
        role: 'None',
        department: null,
        location: null,
        job_title: null,
        employee_number: null,
        supervisor_id: null,
        supervisor_name: null,
        supervisor_sort_name: null,
        start_date: null,
        end_date: null,
        notes: null,
        created_at: null,
        tag_ids: [],
        can_edit: true,
        can_disable: true,
        can_delete: true,
        ...overrides,
    };
}

function mountActions(rowProps: Partial<UserRow> = {}, isSelf = false) {
    return mount(UserRowActions, { props: { row: row(rowProps), isSelf } });
}

type Action = { key: string; label: string; run: () => void };
const keys = (w: ReturnType<typeof mountActions>) =>
    (w.vm.actions as Action[]).map((a) => a.key);

describe('UserRowActions', () => {
    it('offers edit/disable/delete for a fully-permitted other user', () => {
        const w = mountActions();
        expect(keys(w)).toEqual(['edit', 'toggle', 'delete']);
        expect((w.vm.actions as Action[])[1].label).toBe('Disable');
    });

    it('labels the toggle "Enable" when the user is disabled', () => {
        const w = mountActions({ status: 'disabled' });
        const toggle = (w.vm.actions as Action[]).find(
            (a) => a.key === 'toggle',
        )!;
        expect(toggle.label).toBe('Enable');
    });

    it('hides disable + delete on your own row, keeps edit', () => {
        const w = mountActions({}, true);
        expect(keys(w)).toEqual(['edit']);
    });

    it('respects permission flags', () => {
        const w = mountActions({
            can_edit: false,
            can_disable: false,
            can_delete: true,
        });
        expect(keys(w)).toEqual(['delete']);
    });

    it('renders a placeholder (no trigger) when no actions are allowed', () => {
        const w = mountActions({
            can_edit: false,
            can_disable: false,
            can_delete: false,
        });
        expect(keys(w)).toEqual([]);
        expect(w.find('button[aria-label^="Actions for"]').exists()).toBe(
            false,
        );
        expect(w.text()).toContain('—');
    });

    it('emits the right event when an action runs', () => {
        const w = mountActions();
        const actions = w.vm.actions as Action[];
        actions.find((a) => a.key === 'edit')!.run();
        actions.find((a) => a.key === 'toggle')!.run();
        actions.find((a) => a.key === 'delete')!.run();
        expect(w.emitted('edit')).toBeTruthy();
        expect(w.emitted('toggleStatus')).toBeTruthy();
        expect(w.emitted('delete')).toBeTruthy();
    });

    it('shows the actions trigger when actions exist', () => {
        const w = mountActions();
        expect(
            w.find('button[aria-label="Actions for Pat Lee"]').exists(),
        ).toBe(true);
    });
});
