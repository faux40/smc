import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import AttachmentUploadModal from '@/components/AttachmentUploadModal.vue';
import { useAttachmentsStore } from '@/stores/attachments';

vi.mock('axios');

const TYPE = 'App\\Models\\TrainingClass';

async function openModal() {
    const wrapper = mount(AttachmentUploadModal, {
        props: { open: false, morphableType: TYPE, morphableId: 'c1' },
        attachTo: document.body,
    });
    await wrapper.setProps({ open: true });
    await flushPromises();

    return wrapper;
}

// The dialog teleports to <body>; dispatch a drop with a faked dataTransfer.
async function drop(files: File[]): Promise<void> {
    const zone = document.body.querySelector('[data-testid="attachment-dropzone"]')!;
    const ev = new Event('drop', { bubbles: true, cancelable: true });
    Object.defineProperty(ev, 'dataTransfer', { value: { files } });
    zone.dispatchEvent(ev);
    await flushPromises();
}

const rows = () =>
    document.body.querySelectorAll('[data-testid="upload-row"]');
const saveBtn = () =>
    document.body.querySelector<HTMLButtonElement>('[data-testid="upload-save"]')!;

describe('AttachmentUploadModal', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        document.body.innerHTML = '';
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({ data: [] });
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { id: 'a1' },
        });
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('adds a row per dropped file and uploads each on save', async () => {
        await openModal();
        const store = useAttachmentsStore();
        const upload = vi.spyOn(store, 'upload').mockResolvedValue();

        await drop([
            new File(['a'], 'a.pdf', { type: 'application/pdf' }),
            new File(['b'], 'b.pdf', { type: 'application/pdf' }),
        ]);

        expect(rows()).toHaveLength(2);

        saveBtn().click();
        await flushPromises();

        expect(upload).toHaveBeenCalledTimes(2);
        expect(upload.mock.calls[0][0]).toEqual({ type: TYPE, id: 'c1' });
        expect((upload.mock.calls[0][1] as File).name).toBe('a.pdf');
    });

    it('duplicates the first row’s type/description onto newly added files', async () => {
        await openModal();
        const store = useAttachmentsStore();
        const upload = vi.spyOn(store, 'upload').mockResolvedValue();

        await drop([new File(['a'], 'a.pdf', { type: 'application/pdf' })]);
        const firstType = document.body.querySelector<HTMLInputElement>(
            'input#upload_0_type',
        )!;
        firstType.value = 'Sign-in sheet';
        firstType.dispatchEvent(new Event('input', { bubbles: true }));
        await flushPromises();

        await drop([new File(['b'], 'b.pdf', { type: 'application/pdf' })]);

        saveBtn().click();
        await flushPromises();

        expect(upload).toHaveBeenCalledTimes(2);
        expect(upload.mock.calls[1][2]).toEqual({
            type: 'Sign-in sheet',
            description: null,
        });
    });

    it('Save is disabled until at least one file is added', async () => {
        await openModal();
        expect(saveBtn().disabled).toBe(true);

        await drop([new File(['a'], 'a.pdf', { type: 'application/pdf' })]);
        expect(saveBtn().disabled).toBe(false);
    });

    it('surfaces the server 422 validation message on a rejected upload', async () => {
        await openModal();
        const store = useAttachmentsStore();
        vi.spyOn(store, 'upload').mockRejectedValue({
            isAxiosError: true,
            response: {
                status: 422,
                data: {
                    message: 'The file field must be a file of type: pdf, png, jpg, jpeg, gif, webp, doc, docx, xls, xlsx, txt.',
                    errors: { file: ['The file field must be a file of type: ...'] },
                },
            },
        });
        (axios.isAxiosError as unknown as ReturnType<typeof vi.fn>) = vi
            .fn()
            .mockReturnValue(true);

        await drop([new File(['a'], 'a.html', { type: 'text/html' })]);
        saveBtn().click();
        await flushPromises();

        expect(document.body.textContent).toContain(
            'The file field must be a file of type: pdf, png, jpg, jpeg, gif, webp, doc, docx, xls, xlsx, txt.',
        );
    });
});
