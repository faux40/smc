import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';
import ComboboxInput from '@/components/ComboboxInput.vue';

const suggestions = ['Admin', 'Operations', 'Engineering'];

function items(wrapper: ReturnType<typeof mount>) {
    return wrapper
        .findAll('[data-slot="suggestion"]')
        .map((i) => i.text());
}

describe('ComboboxInput', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('opens the full suggestion list on focus', async () => {
        const w = mount(ComboboxInput, { props: { modelValue: '', suggestions } });
        await w.find('input').trigger('focus');
        expect(items(w)).toEqual(['Admin', 'Operations', 'Engineering']);
    });

    it('filters by the current value (case-insensitive substring)', async () => {
        const w = mount(ComboboxInput, {
            props: { modelValue: 'eng', suggestions },
        });
        await w.find('input').trigger('focus');
        expect(items(w)).toEqual(['Engineering']);
    });

    it('re-emits typed input so the parent v-model updates', async () => {
        const w = mount(ComboboxInput, { props: { modelValue: '', suggestions } });
        await w.find('input').setValue('op');
        expect(w.emitted('update:modelValue')?.at(-1)).toEqual(['op']);
    });

    it('emits the picked value and closes when a suggestion is clicked', async () => {
        const w = mount(ComboboxInput, { props: { modelValue: '', suggestions } });
        await w.find('input').trigger('focus');
        await w.findAll('[data-slot="suggestion"]')[1].trigger('click');
        expect(w.emitted('update:modelValue')?.at(-1)).toEqual(['Operations']);
        expect(items(w)).toHaveLength(0);
    });

    it('closes the list on Escape', async () => {
        const w = mount(ComboboxInput, { props: { modelValue: '', suggestions } });
        await w.find('input').trigger('focus');
        expect(items(w).length).toBeGreaterThan(0);
        await w.find('input').trigger('keydown', { key: 'Escape' });
        expect(items(w)).toHaveLength(0);
    });

    it('selects the highlighted suggestion with ArrowDown + Enter', async () => {
        const w = mount(ComboboxInput, { props: { modelValue: '', suggestions } });
        await w.find('input').trigger('focus');
        await w.find('input').trigger('keydown', { key: 'ArrowDown' });
        await w.find('input').trigger('keydown', { key: 'Enter' });
        expect(w.emitted('update:modelValue')?.at(-1)).toEqual(['Admin']);
    });
});
