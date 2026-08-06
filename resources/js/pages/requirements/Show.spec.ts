import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TagsField from '@/components/TagsField.vue';
import RequirementsShow from '@/pages/requirements/Show.vue';
import { useRequirementsStore } from '@/stores/requirements';
import { useRqmtElementsStore } from '@/stores/rqmtElements';
import type { RqmtElementRow } from '@/stores/rqmtElements';
import { useTrainingsStore } from '@/stores/trainings';
import type { TrainingRow } from '@/stores/trainings';

const { authUser } = vi.hoisted(() => ({
    authUser: {
        value: { id: 'me', org_id: 'org1', isAdmin: true } as Record<
            string,
            unknown
        >,
    },
}));

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
    usePage: () => ({ props: { auth: { user: authUser.value } } }),
}));
vi.mock('@/routes/requirements', () => ({
    page: () => '/requirements',
    show: (id: string) => `/requirements/${id}`,
}));

const REQ = { id: 'req1', name: 'Fall Protection', description: 'roof work' };

function training(over: Partial<TrainingRow>): TrainingRow {
    return {
        id: 't',
        name: 'T',
        nickname: null,
        description: null,
        initial_only: false,
        repeating: false,
        std_freq_id: null,
        std_freq_name: null,
        std_freq_repeat_days: null,
        as_needed: false,
        default_hours: null,
        cert_title: null,
        cert_text: null,
        cert_code: null,
        card_template_id: null,
        card_stock_id: null,
        default_trainer: null,
        default_location: null,
        default_address: null,
        superseded_by_id: null,
        can_edit: true,
        can_delete: true,
        ...over,
    };
}

function element(over: Partial<RqmtElementRow>): RqmtElementRow {
    return {
        id: 'e',
        requirement_id: 'req1',
        module_type: 'App\\Models\\Training',
        module_id: 't1',
        name: 'Fall Arrest',
        custom_name: null,
        module_name: 'Fall Arrest',
        description: null,
        initial_only: false,
        repeating: true,
        std_freq_id: 'f1',
        as_needed: false,
        can_edit: true,
        can_delete: true,
        ...over,
    };
}

const STUBS = {
    Heading: true,
    RqmtElementFormModal: true,
    AsyncState: { template: '<div><slot /></div>' },
};

function seedStores() {
    const trainings = useTrainingsStore();
    trainings.library = [
        training({ id: 't1', name: 'Fall Arrest' }),
        training({
            id: 't2',
            name: 'Ladder Safety',
            repeating: true,
            std_freq_id: 'f9',
            description: 'ladders',
        }),
    ];
    trainings.loaded = true;

    const requirements = useRequirementsStore();
    requirements.library = [
        {
            id: 'req1',
            name: 'Fall Protection',
            description: 'roof work',
            elements_count: 1,
            can_edit: true,
            can_delete: true,
        },
    ];
    requirements.loaded = true;

    const elements = useRqmtElementsStore();
    // t1 is already bound as an element; t2 is not.
    elements.lists = {
        req1: [element({ id: 'el1', module_id: 't1', name: 'Fall Arrest' })],
    };
    elements.loaded = { req1: true };

    return { trainings, requirements, elements };
}

async function mountShow(props: Record<string, unknown> = {}) {
    const stores = seedStores();
    const wrapper = mount(RequirementsShow, {
        props: { requirement: REQ, ...props },
        global: { stubs: STUBS },
    });
    await flushPromises();

    return { wrapper, ...stores };
}

describe('requirements/Show — inline details + training shuttle', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        authUser.value = { id: 'me', org_id: 'org1', isAdmin: true };
    });

    it('prefills the details form from the requirement', async () => {
        const { wrapper } = await mountShow();
        const name = wrapper.find('#r_name').element as HTMLInputElement;
        expect(name.value).toBe('Fall Protection');
    });

    it('keeps Save disabled until a detail field changes, then saves', async () => {
        const { wrapper, requirements } = await mountShow();
        const spy = vi
            .spyOn(requirements, 'update')
            .mockResolvedValue(undefined);

        const saveBtn = wrapper
            .findAll('button')
            .find((b) => b.text().includes('Save changes'))!;
        expect(saveBtn.attributes('disabled')).toBeDefined();

        await wrapper.find('#r_name').setValue('Fall Protection 2');
        expect(saveBtn.attributes('disabled')).toBeUndefined();

        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(spy).toHaveBeenCalledWith('req1', {
            name: 'Fall Protection 2',
            description: 'roof work',
        });
    });

    it('lists bound trainings on the assigned side and unbound on available', async () => {
        const { wrapper } = await mountShow();
        const text = wrapper.text();
        // Both appear; the shuttle separates them.
        expect(text).toContain('Fall Arrest'); // assigned element
        expect(text).toContain('Ladder Safety'); // available training
    });

    it('excludes already-bound trainings from the available list', async () => {
        const { wrapper } = await mountShow();
        // Only one Add (+) button — Ladder Safety (t2). Fall Arrest (t1) is bound.
        const addButtons = wrapper.findAll('button[aria-label="Add"]');
        expect(addButtons).toHaveLength(1);
    });

    it('assigns a training by creating an element with snapped timing but NO snapped name', async () => {
        // The name must stay null so the element follows the training through
        // renames — snapshotting it here is how the "Fall Protection" fossil
        // was born.
        const { wrapper, elements } = await mountShow();
        const spy = vi.spyOn(elements, 'create').mockResolvedValue(undefined);

        await wrapper.find('button[aria-label="Add"]').trigger('click');
        await flushPromises();

        expect(spy).toHaveBeenCalledWith('req1', {
            module_type: 'App\\Models\\Training',
            module_id: 't2',
            name: null,
            description: 'ladders',
            initial_only: false,
            repeating: true,
            std_freq_id: 'f9',
            as_needed: false,
        });
    });

    it('shows the live training name beside a diverged override', async () => {
        const { wrapper, elements } = await mountShow();
        elements.lists = {
            req1: [
                element({
                    id: 'el1',
                    module_id: 't1',
                    name: 'Old Label',
                    custom_name: 'Old Label',
                    module_name: 'Fall Arrest',
                }),
            ],
        };
        await flushPromises();

        expect(wrapper.text()).toContain('Old Label → Fall Arrest');
    });

    it('shows just the name when the element follows its training', async () => {
        const { wrapper } = await mountShow();

        expect(wrapper.text()).toContain('Fall Arrest');
        expect(wrapper.text()).not.toContain('→');
    });

    it('removes a bound training by destroying its element', async () => {
        const { wrapper, elements } = await mountShow();
        const spy = vi.spyOn(elements, 'destroy').mockResolvedValue(undefined);

        await wrapper.find('button[aria-label="Remove"]').trigger('click');
        await flushPromises();

        expect(spy).toHaveBeenCalledWith('el1', 'req1');
    });

    it('is read-only for non-managers (no Save, no add/remove)', async () => {
        authUser.value = { id: 'me', org_id: 'org1', isAdmin: false };
        const { wrapper } = await mountShow();

        expect(
            wrapper.findAll('button').some((b) => b.text().includes('Save')),
        ).toBe(false);
        expect(wrapper.find('button[aria-label="Add"]').exists()).toBe(false);
        expect(wrapper.find('button[aria-label="Remove"]').exists()).toBe(
            false,
        );
    });

    it('mounts TagsField for this requirement, hydrated from the page prop', async () => {
        const { wrapper } = await mountShow({ tagIds: ['tag-1'] });

        const field = wrapper.findComponent(TagsField);
        expect(field.exists()).toBe(true);
        expect(field.props('morphableType')).toBe('App\\Models\\Requirement');
        expect(field.props('morphableId')).toBe('req1');
        expect(field.props('initialTagIds')).toEqual(['tag-1']);
    });

    it('defaults tagIds to empty rather than passing undefined through', async () => {
        const { wrapper } = await mountShow();

        expect(wrapper.findComponent(TagsField).props('initialTagIds')).toEqual(
            [],
        );
    });

    it('offers tagging to a non-manager — tags are descriptive, not access-control', async () => {
        authUser.value = { id: 'me', org_id: 'org1', isAdmin: false };
        const { wrapper } = await mountShow();

        const field = wrapper.findComponent(TagsField);
        expect(field.exists()).toBe(true);
        // ...but creating library tags stays admin-only.
        expect(field.props('canManageLibrary')).toBe(false);
    });
});
