import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AttachmentInfoFields from '@/components/AttachmentInfoFields.vue';
import ComboboxInput from '@/components/ComboboxInput.vue';

vi.mock('axios');

describe('AttachmentInfoFields', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: ['Sign-in sheet', 'Test'],
        });
    });

    it('feeds the cached org type vocabulary into the type combobox', async () => {
        const wrapper = mount(AttachmentInfoFields, {
            props: { type: '', description: '' },
        });
        await flushPromises();

        expect(axios.get).toHaveBeenCalledWith(
            '/api/attachments/types',
            expect.anything(),
        );
        expect(wrapper.findComponent(ComboboxInput).props('suggestions')).toEqual(
            ['Sign-in sheet', 'Test'],
        );
    });

    it('emits type + description updates', async () => {
        const wrapper = mount(AttachmentInfoFields, {
            props: { type: '', description: '' },
        });
        await flushPromises();

        wrapper.findComponent(ComboboxInput).vm.$emit('update:modelValue', 'Test');
        await wrapper.find('textarea').setValue('some notes');

        expect(wrapper.emitted('update:type')?.at(-1)).toEqual(['Test']);
        expect(wrapper.emitted('update:description')?.at(-1)).toEqual([
            'some notes',
        ]);
    });
});
