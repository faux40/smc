import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { copyText } from '@/lib/clipboard';

function setClipboard(value: unknown): void {
    Object.defineProperty(navigator, 'clipboard', {
        value,
        configurable: true,
    });
}

describe('copyText', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        setClipboard(undefined);
    });

    it('uses the clipboard API when the page is allowed one', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        setClipboard({ writeText });

        await expect(copyText('${first_name}')).resolves.toBe(true);
        expect(writeText).toHaveBeenCalledWith('${first_name}');
    });

    it('falls back when there is no clipboard API at all', async () => {
        // Dev runs on http://smc.dv — plain HTTP on a hostname is not a secure
        // context, so navigator.clipboard is undefined there. Silently doing
        // nothing is how this went unnoticed the first time.
        setClipboard(undefined);
        const exec = vi.fn().mockReturnValue(true);
        document.execCommand = exec as never;

        await expect(copyText('${cert_id}')).resolves.toBe(true);
        expect(exec).toHaveBeenCalledWith('copy');
    });

    it('falls back when the clipboard API refuses', async () => {
        // Permission denied, or a browser that has the API but blocks it.
        setClipboard({ writeText: vi.fn().mockRejectedValue(new Error('no')) });
        const exec = vi.fn().mockReturnValue(true);
        document.execCommand = exec as never;

        await expect(copyText('${hours}')).resolves.toBe(true);
        expect(exec).toHaveBeenCalledWith('copy');
    });

    it('leaves no scratch element behind', async () => {
        setClipboard(undefined);
        document.execCommand = vi.fn().mockReturnValue(true) as never;

        await copyText('${org_name}');

        expect(document.querySelectorAll('textarea')).toHaveLength(0);
    });

    it('reports failure rather than pretending', async () => {
        // The caller shows "select it and copy" instead of a false "Copied".
        setClipboard(undefined);
        document.execCommand = vi.fn().mockReturnValue(false) as never;

        await expect(copyText('${today}')).resolves.toBe(false);
    });
});
